<?php

declare(strict_types=1);

namespace Tests\Secrets;

require_once __DIR__ . '/SecretsStorageWordPressFunctions.php';

// Native local filesystem behavior is part of this focused composition test.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\Attributes\CoversClass;
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

	private string $root;
	private string $wordpressRoot;
	private string $configPath;
	private string $candidate;
	private string $temporaryBoundary;

	protected function setUp(): void {
		$GLOBALS['ran_booster_secrets_test_translations'] = array();
		$suffix                  = bin2hex( random_bytes( 8 ) );
		$this->root              = sys_get_temp_dir() . '/ran-booster-provisioner-' . $suffix;
		$this->temporaryBoundary = sys_get_temp_dir() . '/ran-booster-temporary-boundary-' . $suffix;
		self::assertTrue( mkdir( $this->root, 0700 ) );
		self::assertTrue( mkdir( $this->temporaryBoundary, 0700 ) );
		$canonicalRoot = realpath( $this->root );
		self::assertIsString( $canonicalRoot );
		$this->root          = $canonicalRoot;
		$this->wordpressRoot = $this->root . '/wordpress';
		$this->configPath    = $this->wordpressRoot . '/wp-config.php';
		$this->candidate     = $this->root . '/private/.ran-booster/0123456789abcdef/secrets.json';

		self::assertTrue( mkdir( $this->wordpressRoot . '/wp-content/plugins/ran-booster', 0700, true ) );
		self::assertTrue( mkdir( $this->root . '/private', 0700 ) );
		self::assertNotFalse(
			file_put_contents(
				$this->configPath,
				"<?php\n\ndefine( 'DB_NAME', 'example' );\n\n/* That's all, stop editing! Happy publishing. */\n"
			)
		);
		self::assertTrue( chmod( $this->configPath, 0600 ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_secrets_test_translations'] );
		$this->removeTree( $this->root );
		$this->removeTree( $this->temporaryBoundary );
	}

	public function testReadOnlyStatusSuggestsSetupWithoutRunningTheProbeOrWriter(): void {
		$provisioner = $this->provisioner();
		$result      = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::SETUP_AVAILABLE, $result->status() );
		self::assertSame( 'setup_available', $result->code() );
		self::assertSame( $this->candidate, $result->candidatePath() );
		self::assertTrue( $result->canProvisionAutomatically() );
		self::assertFalse( $provisioner->probeCalled );
		self::assertFalse( $provisioner->writerCalled );
		self::assertDirectoryDoesNotExist( dirname( $this->candidate ) );
	}

	public function testLocalizesFactoryStatusAndPendingMessagesWithoutChangingCodesOrPaths(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			'Booster can create secure encrypted secrets storage.' => 'Stockage sécurisé prêt.',
			'WordPress must reload before the encrypted secrets path can be trusted.' => 'WordPress doit recharger.',
			'Encrypted secrets storage is incomplete, unreadable or could not be authenticated.' => 'Stockage chiffré incomplet.',
		);
		$provisioner = $this->provisioner();
		$status      = $provisioner->status();
		$pending     = $provisioner->provision();
		$attention   = SecretsStorageProvisioningResult::storageNeedsAttention(
			$this->candidate,
			SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
		);

		self::assertSame( 'Stockage sécurisé prêt.', $status->message() );
		self::assertSame( 'setup_available', $status->code() );
		self::assertSame( $this->candidate, $status->candidatePath() );
		self::assertSame( 'WordPress doit recharger.', $pending->message() );
		self::assertSame( 'pending_verification', $pending->code() );
		self::assertSame( $this->candidate, $pending->candidatePath() );
		self::assertSame( 'Stockage chiffré incomplet.', $attention->message() );
	}

	public function testLocalizesConfiguredStorageItemDiagnosticsWithoutChangingCodesModesOrPaths(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			"Configured secrets storage item\004directory" => 'répertoire',
			"Configured secrets storage item\004file"      => 'fichier',
			"Configured secrets storage item\004lock file" => 'fichier verrou',
			'The configured secrets %1$s uses mode %2$04o; mode %3$04o is required.' => 'Le secret %1$s utilise le mode %2$04o ; le mode %3$04o est requis.',
		);

		$directory = $this->root . '/translated-directory';
		self::assertTrue( mkdir( $directory, 0755 ) );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $directory . '/secrets.json';
		$directoryResult         = $provisioner->status();

		self::assertSame( 'storage_directory_unusable', $directoryResult->code() );
		self::assertSame( $provisioner->configured, $directoryResult->candidatePath() );
		self::assertStringContainsString( 'répertoire', $directoryResult->message() );
		self::assertStringContainsString( '0755', $directoryResult->message() );
		self::assertStringContainsString( '0700', $directoryResult->message() );

		self::assertTrue( chmod( $directory, 0700 ) );
		$file = $provisioner->configured;
		self::assertNotFalse( file_put_contents( $file, '{}' ) );
		self::assertTrue( chmod( $file, 0644 ) );
		$fileResult = $provisioner->status();

		self::assertSame( 'storage_file_unusable', $fileResult->code() );
		self::assertSame( $file, $fileResult->candidatePath() );
		self::assertStringContainsString( 'fichier', $fileResult->message() );
		self::assertStringContainsString( '0644', $fileResult->message() );
		self::assertStringContainsString( '0600', $fileResult->message() );

		self::assertTrue( chmod( $file, 0600 ) );
		$lock = $file . '.lock';
		self::assertNotFalse( file_put_contents( $lock, '' ) );
		self::assertTrue( chmod( $lock, 0644 ) );
		$lockResult = $provisioner->status();

		self::assertSame( 'storage_lock_unusable', $lockResult->code() );
		self::assertSame( $file, $lockResult->candidatePath() );
		self::assertStringContainsString( 'fichier verrou', $lockResult->message() );
		self::assertStringContainsString( '0644', $lockResult->message() );
		self::assertStringContainsString( '0600', $lockResult->message() );
	}

	public function testLocalizesModeOwnershipReadabilityAndWritabilityIssues(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			"Configured secrets storage item\004file" => 'fichier',
			'The configured secrets %1$s uses mode %2$04o; mode %3$04o is required.' => 'Mode : %1$s, %2$04o au lieu de %3$04o.',
			'The configured secrets %s is not owned by the PHP process user.' => 'Propriétaire PHP incorrect : %s.',
			'The configured secrets %s is not readable by PHP.' => 'PHP ne peut pas lire : %s.',
			'The configured secrets %s is not writable by PHP.' => 'PHP ne peut pas écrire : %s.',
		);
		$method = new \ReflectionMethod( SecretsStorageProvisioner::class, 'accessIssues' );
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

		$fallbackIssues = $method->invoke(
			$this->provisioner(),
			$this->root . '/does-not-exist',
			array(
				'mode' => 0644,
				'uid'  => -1,
			),
			0600,
			'unexpected item'
		);

		self::assertSame( 'Mode : unexpected item, 0644 au lieu de 0600.', $fallbackIssues[0] );
	}

	public function testLocalizesManagedStorageDiagnosticBranchesWithoutChangingMatchRouting(): void {
		$directory = $this->root . '/translated-managed-diagnostics';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$provisioner                = $this->provisioner();
		$provisioner->configured    = $directory . '/secrets.json';
		$provisioner->healthFailure = true;
		$generic                    = \RAN\Secrets\SecretsStorageUnavailable::REASON_GENERIC;
		$cases                      = array(
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
			$reason                            = $case[0];
			$message                           = $case[1];
			$code                              = $case[2];
			$provisioner->healthFailureReason  = $reason;
			$provisioner->healthFailureMessage = $message;
			$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
				$this->managedDiagnosticMessageForCode( $code ) => 'Diagnostic traduit : ' . $code,
			);

			$result = $provisioner->status();

			self::assertSame( $code, $result->code(), $message );
			self::assertSame( $provisioner->configured, $result->candidatePath(), $message );
			self::assertSame( 'Diagnostic traduit : ' . $code, $result->message(), $message );
		}
	}

	public function testUnavailableLocationRetainsBoundedDiscardedCandidateDiagnostics(): void {
		$provisioner                      = $this->provisioner();
		$provisioner->resolverFails       = true;
		$provisioner->discardedCandidates = array(
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
		self::assertSame( $provisioner->discardedCandidates, $result->discardedCandidates() );
		self::assertFalse( $provisioner->probeCalled );
		self::assertFalse( $provisioner->writerCalled );
	}

	public function testProvisionProbesThenWritesAndRemainsPendingUntilAFreshConfigurationCheck(): void {
		$provisioner = $this->provisioner();

		$pending = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::PENDING_VERIFICATION, $pending->status() );
		self::assertTrue( $pending->requiresNextRequestVerification() );
		self::assertTrue( $provisioner->probeCalled );
		self::assertTrue( $provisioner->writerCalled );
		self::assertDirectoryExists( dirname( $this->candidate ) );
		self::assertFileDoesNotExist( $this->candidate );
		self::assertStringContainsString(
			"define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->candidate ) . "' );",
			(string) file_get_contents( $this->configPath )
		);
		self::assertStringNotContainsString(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE',
			(string) file_get_contents( $this->configPath )
		);

		$provisioner->configured = $this->candidate;
		$configured              = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $configured->status() );
		self::assertTrue( $configured->hasConfiguredPath() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC, $configured->pathSource() );
	}

	public function testValidManualOverrideIsHandledBeforeAutomaticSuggestionResolution(): void {
		$manual = $this->root . '/manual';
		self::assertTrue( mkdir( $manual, 0700 ) );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $manual . '/secrets.json';

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $result->status() );
		self::assertSame( $manual . '/secrets.json', $result->candidatePath() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL, $result->pathSource() );
		self::assertTrue( $provisioner->resolverCalled );
		self::assertFalse( $provisioner->probeCalled );
		self::assertFalse( $provisioner->writerCalled );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testDirectoryConstantWinsAndAppendsTheManagedFilename(): void {
		$wpConfigDirectory = $this->root . '/public';
		$directory         = dirname( $wpConfigDirectory ) . '/operator-private';
		self::assertTrue( mkdir( $directory, 0700 ) );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $directory );
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $this->root . '/legacy/secrets.json' );

		$provisioner                           = $this->provisioner();
		$provisioner->readRuntimeConfiguration = true;
		$result                                = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $result->status() );
		self::assertSame( $directory . '/secrets.json', $result->candidatePath() );
		self::assertSame( $directory . '/secrets.json', ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testLegacyFileConstantAcceptsTheNormalizedWpConfigRelativeForm(): void {
		$wpConfigDirectory = $this->root . '/public';
		$directory         = dirname( $wpConfigDirectory ) . '/operator-file';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$file = $directory . '/secrets.json';
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $file );

		$provisioner                           = $this->provisioner();
		$provisioner->readRuntimeConfiguration = true;
		$result                                = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $result->status() );
		self::assertSame( $file, $result->candidatePath() );
		self::assertSame( $file, ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRawRelativeDirectoryConstantIsRejectedByBothConsumers(): void {
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', 'relative/private' );

		$provisioner                           = $this->provisioner();
		$provisioner->readRuntimeConfiguration = true;
		$result                                = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_invalid', $result->code() );
		self::assertNull( $result->candidatePath() );
		self::assertNull( ( new SecretsFile() )->path() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRawRelativeFileConstantIsRejectedByBothConsumers(): void {
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', 'relative/private/secrets.json' );

		$provisioner                           = $this->provisioner();
		$provisioner->readRuntimeConfiguration = true;
		$result                                = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_invalid', $result->code() );
		self::assertNull( $result->candidatePath() );
		self::assertNull( ( new SecretsFile() )->path() );
	}

	public function testConfiguredPathReportsAuthenticatedAndBrokenStorageTruthfully(): void {
		$manual = $this->root . '/manual-health';
		self::assertTrue( mkdir( $manual, 0700 ) );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $manual . '/secrets.json';
		$provisioner->healthy    = true;

		self::assertSame(
			SecretsStorageProvisioningResult::STORAGE_HEALTHY,
			$provisioner->status()->status()
		);

		$provisioner->healthFailure = true;
		$result                     = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION, $result->status() );
		self::assertSame( 'storage_unavailable', $result->code() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function testConfiguredPathExplainsMissingAndInsecureDirectory(): void {
		$directory               = $this->root . '/manual-attention';
		$provisioner             = $this->provisioner();
		$provisioner->configured = $directory . '/secrets.json';

		$result = $provisioner->status();
		self::assertSame( 'storage_directory_unavailable', $result->code() );
		self::assertStringContainsString( 'execute/traverse', $result->message() );

		self::assertTrue( mkdir( $directory, 0755 ) );
		$result = $provisioner->status();
		self::assertSame( 'storage_directory_unusable', $result->code() );
		self::assertStringContainsString( '0755', $result->message() );
		self::assertStringContainsString( '0700', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function testConfiguredPathExplainsUnsafeFilePermissions(): void {
		$directory = $this->root . '/manual-file-attention';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$file = $directory . '/secrets.json';
		self::assertNotFalse( file_put_contents( $file, '{}' ) );
		self::assertTrue( chmod( $file, 0644 ) );

		$provisioner             = $this->provisioner();
		$provisioner->configured = $file;
		$result                  = $provisioner->status();

		self::assertSame( 'storage_file_unusable', $result->code() );
		self::assertStringContainsString( '0644', $result->message() );
		self::assertStringContainsString( '0600', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function testConfiguredPathExplainsMissingAndUnsafeLockFile(): void {
		$directory = $this->root . '/manual-lock-attention';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$file = $directory . '/secrets.json';
		self::assertNotFalse( file_put_contents( $file, '{}' ) );
		self::assertTrue( chmod( $file, 0600 ) );

		$provisioner             = $this->provisioner();
		$provisioner->configured = $file;
		$result                  = $provisioner->status();
		self::assertSame( 'storage_lock_missing', $result->code() );
		self::assertStringContainsString( 'matching lock file is missing', $result->message() );

		$lock = $file . '.lock';
		self::assertNotFalse( file_put_contents( $lock, '' ) );
		self::assertTrue( chmod( $lock, 0644 ) );
		$result = $provisioner->status();
		self::assertSame( 'storage_lock_unusable', $result->code() );
		self::assertStringContainsString( '0644', $result->message() );
		self::assertStringContainsString( '0600', $result->message() );

		self::assertTrue( chmod( $lock, 0600 ) );
		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $provisioner->status()->status() );
	}

	public function testConfiguredPathDistinguishesIncompleteAndAuthenticationFailures(): void {
		$directory = $this->root . '/manual-managed-attention';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$provisioner                = $this->provisioner();
		$provisioner->configured    = $directory . '/secrets.json';
		$provisioner->healthFailure = true;

		$provisioner->healthFailureMessage = 'The encrypted Booster secrets store is incomplete.';
		self::assertSame( 'storage_incomplete', $provisioner->status()->code() );

		$provisioner->healthFailureReason = 'storage_key_missing';
		$result                           = $provisioner->status();
		self::assertSame( 'storage_key_missing', $result->code() );
		self::assertStringContainsString( 'database encryption key is missing', $result->message() );
		self::assertStringContainsString( 'will not delete unauthenticated ciphertext', $result->message() );

		$provisioner->healthFailureReason  = \RAN\Secrets\SecretsStorageUnavailable::REASON_GENERIC;
		$provisioner->healthFailureMessage = 'The encrypted Booster secrets document could not be authenticated.';
		$result                            = $provisioner->status();
		self::assertSame( 'storage_authentication_failed', $result->code() );
		self::assertStringContainsString( 'same backup', $result->message() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function testManualPathDoesNotRequireAutomaticFilesystemEligibility(): void {
		$manual = $this->root . '/manual-platform';
		self::assertTrue( mkdir( $manual, 0700 ) );
		$provisioner                = $this->provisioner();
		$provisioner->configured    = $manual . '/secrets.json';
		$provisioner->localPlatform = false;

		self::assertSame(
			SecretsStorageProvisioningResult::PATH_CONFIGURED,
			$provisioner->status()->status()
		);
	}

	public function testManualOverrideInsideWordPressIsRejectedWithPrivilegedCorrectionPath(): void {
		$provisioner             = $this->provisioner();
		$provisioner->configured = $this->wordpressRoot . '/secrets.json';

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION, $result->status() );
		self::assertSame( 'configured_path_unsafe', $result->code() );
		self::assertSame( $this->wordpressRoot . '/secrets.json', $result->candidatePath() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_MANUAL, $result->pathSource() );
		self::assertStringContainsString( 'outside the public web root', $result->message() );
		self::assertFalse( $provisioner->resolverCalled );
	}

	public function testUnsafeBoosterOwnedDirectoryDefinitionIsAttributedToAutomaticSetup(): void {
		$configured = $this->wordpressRoot . '/private/secrets.json';
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $configured );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $configured;

		$result = $provisioner->status();

		self::assertSame( 'configured_path_unsafe', $result->code() );
		self::assertSame( SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC, $result->pathSource() );
		self::assertFalse( $provisioner->resolverCalled );
	}

	public function testManualOverrideRejectsSymlinkedComponentsAndUnsafeExistingTarget(): void {
		$actual = $this->root . '/manual-actual';
		$link   = $this->root . '/manual-link';
		self::assertTrue( mkdir( $actual, 0700 ) );
		self::assertTrue( symlink( $actual, $link ) );

		$provisioner             = $this->provisioner();
		$provisioner->configured = $link . '/secrets.json';
		self::assertSame( 'configured_path_unsafe', $provisioner->status()->code() );

		$provisioner->configured = $actual . '/secrets.json';
		self::assertNotFalse( file_put_contents( $provisioner->configured, '{}' ) );
		self::assertTrue( chmod( $provisioner->configured, 0644 ) );
		self::assertSame( 'storage_file_unusable', $provisioner->status()->code() );

		self::assertTrue( chmod( $provisioner->configured, 0600 ) );
		self::assertNotFalse( file_put_contents( $provisioner->configured . '.lock', '' ) );
		self::assertTrue( chmod( $provisioner->configured . '.lock', 0600 ) );
		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $provisioner->status()->status() );
	}

	public function testOnlyOneActuallyIncludedCoreConfigurationCandidateIsAccepted(): void {
		$provisioner           = $this->provisioner();
		$other                 = $this->root . '/other/wp-config.php';
		$provisioner->included = array( $this->configPath, $other );
		self::assertTrue( mkdir( dirname( $other ), 0700 ) );
		self::assertNotFalse( file_put_contents( $other, "<?php\n" ) );

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'wp_config_unavailable', $result->code() );
		self::assertFalse( $provisioner->probeCalled );
	}

	public function testParentConfigurationUsesTheCoreFallbackRule(): void {
		self::assertTrue( unlink( $this->configPath ) );
		$parentConfig = $this->root . '/wp-config.php';
		self::assertNotFalse(
			file_put_contents(
				$parentConfig,
				"<?php\n\n/* That's all, stop editing! Happy publishing. */\n"
			)
		);
		self::assertTrue( chmod( $parentConfig, 0600 ) );
		$provisioner           = $this->provisioner();
		$provisioner->included = array( $parentConfig );

		self::assertSame(
			SecretsStorageProvisioningResult::SETUP_AVAILABLE,
			$provisioner->status()->status()
		);

		self::assertNotFalse( file_put_contents( $this->root . '/wp-settings.php', "<?php\n" ) );
		self::assertSame( 'wp_config_unavailable', $provisioner->status()->code() );
	}

	public function testUnsupportedEnvironmentStopsBeforeLocationOrFilesystemWork(): void {
		$provisioner            = $this->provisioner();
		$provisioner->multisite = true;

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::UNSUPPORTED, $result->status() );
		self::assertSame( 'multisite_unsupported', $result->code() );
		self::assertFalse( $provisioner->resolverCalled );
		self::assertFalse( $provisioner->probeCalled );
		self::assertFalse( $provisioner->writerCalled );
	}

	public function testProbeFailureReturnsAStableCodeWithoutCallingTheWriterOrLeakingThePath(): void {
		$provisioner             = $this->provisioner();
		$provisioner->probeFails = true;

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'filesystem_probe_failed', $result->code() );
		self::assertFalse( $provisioner->writerCalled );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function testCandidateIsRevalidatedAfterProbeBeforeWpConfigMutation(): void {
		$provisioner                   = $this->provisioner();
		$provisioner->unsafeAfterProbe = true;

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'candidate_path_unsafe', $result->code() );
		self::assertTrue( $provisioner->probeCalled );
		self::assertFalse( $provisioner->writerCalled );
		self::assertStringNotContainsString( $this->root, $result->message() );
		self::assertStringNotContainsString(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR',
			(string) file_get_contents( $this->configPath )
		);
	}

	public function testWriterFailureIsReducedToItsStableNonPathBearingReason(): void {
		$provisioner                    = $this->provisioner();
		$provisioner->writerFailureCode = 'config_changed';

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'config_changed', $result->code() );
		self::assertStringNotContainsString( $this->root, $result->message() );
	}

	public function testUniqueAuthenticatedProviderFitSiblingCanBeAdoptedByOpaqueRevision(): void {
		$old = $this->recoveryStore( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                      = $this->provisioner();
		$provisioner->configured          = $this->candidate;
		$provisioner->healthFailure       = true;
		$provisioner->healthFailureReason = 'storage_file_missing';
		$status                           = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::STORAGE_NEEDS_ATTENTION, $status->status() );
		$recovery = $provisioner->recoveryState( $status );
		self::assertIsArray( $recovery );
		self::assertSame( 'available', $recovery['state'] );
		self::assertSame( $old, $recovery['candidate_path'] );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', (string) $recovery['token'] );

		$result = $provisioner->adoptRecovery( (string) $recovery['token'] );
		self::assertTrue( $result->requiresNextRequestVerification() );
		$config = (string) file_get_contents( $this->configPath );
		self::assertStringContainsString( dirname( $old ), $config );
		self::assertStringNotContainsString( dirname( $this->candidate ), $config );
		self::assertSame( array( $old, $old ), $provisioner->authenticatedCandidates );
	}

	public function testExistingManagedLockDoesNotHideAnAuthenticatedSibling(): void {
		$old = $this->recoveryStore( 'abcdef0123456789' );
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700 ) );
		self::assertNotFalse( file_put_contents( $this->candidate . '.lock', '' ) );
		self::assertTrue( chmod( $this->candidate . '.lock', 0600 ) );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                      = $this->provisioner();
		$provisioner->configured          = $this->candidate;
		$provisioner->healthFailure       = true;
		$provisioner->healthFailureReason = 'storage_file_missing';

		$recovery = $provisioner->recoveryState( $provisioner->status() );

		self::assertIsArray( $recovery );
		self::assertSame( 'available', $recovery['state'] );
		self::assertSame( $old, $recovery['candidate_path'] );
	}

	public function testExplicitResetIsOfferedOnlyForTheOrphanedKeyStateAndRequiresTypedConfirmation(): void {
		$GLOBALS['ran_booster_secrets_test_translations']['ran-booster'] = array(
			'Booster found a database encryption key without its matching encrypted file. Restore the matching file if possible, or explicitly reset this empty credential store.' => 'Clé de stockage orpheline.',
			'Incomplete credential storage was reset. Booster will initialize fresh encrypted storage when you next save or import a credential.' => 'Stockage réinitialisé.',
		);
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700, true ) );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                         = $this->provisioner();
		$provisioner->configured             = $this->candidate;
		$provisioner->healthFailure          = true;
		$provisioner->healthFailureReason    = 'storage_file_missing';
		$provisioner->orphanedResetAvailable = true;
		$status                              = $provisioner->status();

		$offer = $provisioner->recoveryState( $status );
		self::assertIsArray( $offer );
		self::assertSame( 'reset_available', $offer['state'] );
		self::assertSame( 'Clé de stockage orpheline.', $offer['message'] );
		self::assertSame( SecretsStorageProvisioner::RESET_CONFIRMATION, $offer['confirmation'] );

		$invalid = $provisioner->resetOrphanedStorage( 'reset storage' );
		self::assertSame( 'storage_reset_request_invalid', $invalid->code() );
		self::assertSame( array(), $provisioner->resetCandidates );

		$reset = $provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( SecretsStorageProvisioningResult::PATH_CONFIGURED, $reset->status() );
		self::assertSame( 'storage_reset', $reset->code() );
		self::assertSame( 'Stockage réinitialisé.', $reset->message() );
		self::assertSame( array( $this->candidate ), $provisioner->resetCandidates );

		$replay = $provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset_state_changed', $replay->code() );
		self::assertSame( array( $this->candidate ), $provisioner->resetCandidates );
	}

	public function testExplicitResetAlsoHandlesSecureOrphanedCiphertextWithoutItsDatabaseKey(): void {
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700, true ) );
		self::assertNotFalse( file_put_contents( $this->candidate, 'encrypted-canary' ) );
		self::assertTrue( chmod( $this->candidate, 0600 ) );
		self::assertNotFalse( file_put_contents( $this->candidate . '.lock', '' ) );
		self::assertTrue( chmod( $this->candidate . '.lock', 0600 ) );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                                   = $this->provisioner();
		$provisioner->configured                       = $this->candidate;
		$provisioner->healthFailure                    = true;
		$provisioner->healthFailureReason              = 'storage_key_missing';
		$provisioner->orphanedCiphertextResetAvailable = true;

		$offer = $provisioner->recoveryState( $provisioner->status() );
		self::assertIsArray( $offer );
		self::assertSame( 'reset_available', $offer['state'] );
		self::assertStringContainsString( 'without its matching database key', $offer['message'] );

		$reset = $provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset', $reset->code() );
		self::assertSame( array( $this->candidate ), $provisioner->resetCiphertextCandidates );
		self::assertSame( array(), $provisioner->resetCandidates );

		$replay = $provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset_state_changed', $replay->code() );
	}

	public function testMissingDatabaseKeyCannotResetWhilePriorStorageMaterialNeedsReview(): void {
		$this->recoveryStore( 'abcdef0123456789' );
		self::assertTrue( mkdir( dirname( $this->candidate ), 0700, true ) );
		self::assertNotFalse( file_put_contents( $this->candidate, 'encrypted-canary' ) );
		self::assertTrue( chmod( $this->candidate, 0600 ) );
		self::assertNotFalse( file_put_contents( $this->candidate . '.lock', '' ) );
		self::assertTrue( chmod( $this->candidate . '.lock', 0600 ) );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                                   = $this->provisioner();
		$provisioner->configured                       = $this->candidate;
		$provisioner->healthFailure                    = true;
		$provisioner->healthFailureReason              = 'storage_key_missing';
		$provisioner->orphanedCiphertextResetAvailable = true;

		$offer = $provisioner->recoveryState( $provisioner->status() );

		self::assertIsArray( $offer );
		self::assertSame( 'blocked', $offer['state'] );
		self::assertNull( $offer['confirmation'] );
		self::assertStringContainsString( 'no database key is available to authenticate it', $offer['message'] );
		self::assertSame(
			'storage_reset_state_changed',
			$provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION )->code()
		);
		self::assertSame( array(), $provisioner->resetCiphertextCandidates );
	}

	public function testAuthenticatedSiblingRecoveryTakesPriorityOverReset(): void {
		$this->recoveryStore( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                         = $this->provisioner();
		$provisioner->configured             = $this->candidate;
		$provisioner->healthFailure          = true;
		$provisioner->healthFailureReason    = 'storage_file_missing';
		$provisioner->orphanedResetAvailable = true;

		$offer = $provisioner->recoveryState( $provisioner->status() );

		self::assertIsArray( $offer );
		self::assertSame( 'available', $offer['state'] );
		$result = $provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION );
		self::assertSame( 'storage_reset_state_changed', $result->code() );
		self::assertSame( array(), $provisioner->resetCandidates );
	}

	public function testAuthenticatedUnsafeSiblingIsReportedButCannotBeAdopted(): void {
		$this->recoveryStore( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner              = $this->provisioner();
		$provisioner->configured  = $this->candidate;
		$provisioner->forceUnsafe = true;

		$recovery = $provisioner->recoveryState( $provisioner->status() );

		self::assertIsArray( $recovery );
		self::assertSame( 'blocked', $recovery['state'] );
		self::assertNull( $recovery['candidate_path'] );
		self::assertNull( $recovery['token'] );
		self::assertStringContainsString( 'does not pass', $recovery['message'] );
	}

	public function testAmbiguousOrUnauthenticatedSiblingDoesNotProduceAnAdoptionToken(): void {
		$this->recoveryStore( 'abcdef0123456789' );
		$this->recoveryStore( 'fedcba9876543210' );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner             = $this->provisioner();
		$provisioner->configured = $this->candidate;

		$ambiguous = $provisioner->recoveryState( $provisioner->status() );
		self::assertIsArray( $ambiguous );
		self::assertSame( 'ambiguous', $ambiguous['state'] );
		self::assertNull( $ambiguous['token'] );

		$provisioner->recoveryAuthenticationFails = true;
		$blocked                                  = $provisioner->recoveryState( $provisioner->status() );
		self::assertIsArray( $blocked );
		self::assertSame( 'blocked', $blocked['state'] );
		self::assertStringContainsString( 'could not inspect completely', $blocked['message'] );
	}

	public function testMalformedOrOverLimitSiblingScanBlocksReset(): void {
		$malformed = $this->recoveryStore( 'abcdef0123456789' );
		self::assertTrue( unlink( $malformed . '.lock' ) );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                         = $this->provisioner();
		$provisioner->configured             = $this->candidate;
		$provisioner->healthFailure          = true;
		$provisioner->healthFailureReason    = 'storage_file_missing';
		$provisioner->orphanedResetAvailable = true;

		$blocked = $provisioner->recoveryState( $provisioner->status() );
		self::assertIsArray( $blocked );
		self::assertSame( 'blocked', $blocked['state'] );
		self::assertNull( $blocked['confirmation'] );
		self::assertSame(
			'storage_reset_state_changed',
			$provisioner->resetOrphanedStorage( SecretsStorageProvisioner::RESET_CONFIRMATION )->code()
		);
		self::assertSame( array(), $provisioner->resetCandidates );

		self::assertTrue( unlink( $malformed ) );
		for ( $index = 0; $index <= 64; ++$index ) {
			self::assertTrue( mkdir( dirname( dirname( $this->candidate ) ) . '/' . sprintf( '%016x', $index ), 0700 ) );
		}
		$overflow = $provisioner->recoveryState( $provisioner->status() );
		self::assertIsArray( $overflow );
		self::assertSame( 'blocked', $overflow['state'] );
		self::assertNull( $overflow['confirmation'] );
	}

	public function testAuthenticatedProviderUnfitSiblingIsVisibleButNotAdoptable(): void {
		$this->recoveryStore( 'abcdef0123456789' );
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->candidate );
		$provisioner                         = $this->provisioner();
		$provisioner->configured             = $this->candidate;
		$provisioner->recoveryCredentialsFit = false;

		$recovery = $provisioner->recoveryState( $provisioner->status() );

		self::assertIsArray( $recovery );
		self::assertSame( 'blocked', $recovery['state'] );
		self::assertNull( $recovery['token'] );
		self::assertStringContainsString( 'do not pass their current provider policy', $recovery['message'] );
	}

	private function managedDiagnosticMessageForCode( string $code ): string {
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
		$provisioner                = new TestSecretsStorageProvisioner( $this->temporaryBoundary );
		$provisioner->root          = $this->wordpressRoot;
		$provisioner->candidate     = $this->candidate;
		$provisioner->included      = array( $this->configPath );
		$provisioner->configured    = null;
		$provisioner->sodium        = true;
		$provisioner->multisite     = false;
		$provisioner->localPlatform = true;

		return $provisioner;
	}

	private function recoveryStore( string $fingerprint ): string {
		$path = dirname( dirname( $this->candidate ) ) . '/' . $fingerprint . '/secrets.json';
		self::assertTrue( mkdir( dirname( $path ), 0700, true ) );
		self::assertNotFalse( file_put_contents( $path, '{}' ) );
		self::assertTrue( chmod( $path, 0600 ) );
		self::assertNotFalse( file_put_contents( $path . '.lock', '' ) );
		self::assertTrue( chmod( $path . '.lock', 0600 ) );

		return $path;
	}

	private function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->removeTree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}

final class TestSecretsStorageProvisioner extends SecretsStorageProvisioner {

	public string $root      = '';
	public string $candidate = '';
	/** @var list<string> */
	public array $included                        = array();
	public string|false|null $configured          = null;
	public bool $sodium                           = true;
	public bool $multisite                        = false;
	public bool $localPlatform                    = true;
	public bool $resolverCalled                   = false;
	public bool $resolverFails                    = false;
	public bool $probeCalled                      = false;
	public bool $writerCalled                     = false;
	public bool $probeFails                       = false;
	public ?string $writerFailureCode             = null;
	public bool $healthy                          = false;
	public bool $healthFailure                    = false;
	public string $healthFailureMessage           = 'Fixture storage failure.';
	public string $healthFailureReason            = \RAN\Secrets\SecretsStorageUnavailable::REASON_GENERIC;
	public bool $readRuntimeConfiguration         = false;
	public bool $forceUnsafe                      = false;
	public bool $unsafeAfterProbe                 = false;
	public bool $recoveryAuthenticationFails      = false;
	public bool $recoveryCredentialsFit           = true;
	public bool $orphanedResetAvailable           = false;
	public bool $orphanedCiphertextResetAvailable = false;
	/** @var list<string> */
	public array $authenticatedCandidates = array();
	/** @var list<string> */
	public array $resetCandidates = array();
	/** @var list<string> */
	public array $resetCiphertextCandidates = array();
	/** @var list<array{directory:string,code:string,reason:string,component:string|null}> */
	public array $discardedCandidates = array();

	public function __construct( string $temporaryBoundary ) {
		parent::__construct(
			new PrivateLocationCandidateResolver( $temporaryBoundary ),
			new PosixFilesystemProbe(),
			new WpConfigSecretsPathWriter()
		);
	}

	protected function resolveCandidate( ?array &$discarded = null ): ?string {
		$this->resolverCalled = true;
		$discarded            = $this->discardedCandidates;

		return $this->resolverFails ? null : $this->candidate;
	}

	protected function probeCandidate( string $candidate ): bool {
		$this->probeCalled = true;

		return ! $this->probeFails && parent::probeCandidate( $candidate );
	}

	protected function validateConfiguredCandidate( string $candidate ): bool {
		return ! $this->forceUnsafe
			&& ! ( $this->unsafeAfterProbe && $this->probeCalled )
			&& parent::validateConfiguredCandidate( $candidate );
	}

	protected function recoveryCredentialsFit( string $candidate ): bool {
		$this->authenticatedCandidates[] = $candidate;
		if ( $this->recoveryAuthenticationFails ) {
			throw new \RuntimeException( 'Provider credential fitness failed.' );
		}

		return $this->recoveryCredentialsFit;
	}

	protected function currentCiphertextIsAbsent( string $current ): bool {
		return ! file_exists( $current ) && ! is_link( $current );
	}

	protected function orphanedKeyResetAvailable( string $current ): bool {
		return $this->orphanedResetAvailable;
	}

	protected function resetOrphanedKey( string $current ): void {
		$this->resetCandidates[]      = $current;
		$this->orphanedResetAvailable = false;
	}

	protected function orphanedCiphertextResetAvailable( string $current ): bool {
		return $this->orphanedCiphertextResetAvailable;
	}

	protected function resetOrphanedCiphertext( string $current ): void {
		$this->resetCiphertextCandidates[]      = $current;
		$this->orphanedCiphertextResetAvailable = false;
	}

	protected function writeConfiguration( string $config, string $candidate ): WpConfigPathWriteResult {
		$this->writerCalled = true;
		if ( null !== $this->writerFailureCode ) {
			throw new \RAN\Secrets\WpConfigPathWriteException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test seam throws a stable fixture code, never rendered output.
				$this->writerFailureCode,
				'The WordPress configuration changed before it could be edited.'
			);
		}

		return parent::writeConfiguration( $config, $candidate );
	}

	protected function wordpressRoot(): string {
		return $this->root;
	}

	protected function contentDirectory(): string {
		return $this->root . '/wp-content';
	}

	protected function pluginDirectory(): string {
		return $this->root . '/wp-content/plugins/ran-booster';
	}

	protected function documentRoot(): ?string {
		return $this->root;
	}

	protected function includedFiles(): array {
		return $this->included;
	}

	protected function configuredPath(): string|false|null {
		return $this->readRuntimeConfiguration ? parent::configuredPath() : $this->configured;
	}

	protected function isMultisiteInstallation(): bool {
		return $this->multisite;
	}

	protected function sodiumAvailable(): bool {
		return $this->sodium;
	}

	protected function supportedLocalPlatform(): bool {
		return $this->localPlatform;
	}

	protected function managedStorageHealthy(): bool {
		if ( $this->healthFailure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only fixed fixture message.
			throw new \RAN\Secrets\SecretsStorageUnavailable( $this->healthFailureMessage, $this->healthFailureReason );
		}

		return $this->healthy;
	}
}

<?php

declare(strict_types=1);

namespace Tests\Secrets;

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
			"define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '" . $this->candidate . "' );",
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
		self::assertSame( 'storage_directory_missing', $result->code() );
		self::assertSame( 'The configured secrets directory does not exist or is not visible to the PHP process.', $result->message() );

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

	public function testConfiguredPathDistinguishesIncompleteAndAuthenticationFailures(): void {
		$directory = $this->root . '/manual-managed-attention';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$provisioner                = $this->provisioner();
		$provisioner->configured    = $directory . '/secrets.json';
		$provisioner->healthFailure = true;

		$provisioner->healthFailureMessage = 'The encrypted Booster secrets store is incomplete.';
		self::assertSame( 'storage_incomplete', $provisioner->status()->code() );

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

	public function testManualOverrideInsideWordPressIsRejectedWithoutExposingItsPath(): void {
		$provisioner             = $this->provisioner();
		$provisioner->configured = $this->wordpressRoot . '/secrets.json';

		$result = $provisioner->status();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'configured_path_unsafe', $result->code() );
		self::assertNull( $result->candidatePath() );
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

	public function testWriterFailureIsReducedToItsStableNonPathBearingReason(): void {
		$provisioner                    = $this->provisioner();
		$provisioner->writerFailureCode = 'config_changed';

		$result = $provisioner->provision();

		self::assertSame( SecretsStorageProvisioningResult::MANUAL_REQUIRED, $result->status() );
		self::assertSame( 'config_changed', $result->code() );
		self::assertStringNotContainsString( $this->root, $result->message() );
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
	public array $included                = array();
	public string|false|null $configured  = null;
	public bool $sodium                   = true;
	public bool $multisite                = false;
	public bool $localPlatform            = true;
	public bool $resolverCalled           = false;
	public bool $probeCalled              = false;
	public bool $writerCalled             = false;
	public bool $probeFails               = false;
	public ?string $writerFailureCode     = null;
	public bool $healthy                  = false;
	public bool $healthFailure            = false;
	public string $healthFailureMessage   = 'Fixture storage failure.';
	public bool $readRuntimeConfiguration = false;

	public function __construct( string $temporaryBoundary ) {
		parent::__construct(
			new PrivateLocationCandidateResolver( $temporaryBoundary ),
			new PosixFilesystemProbe(),
			new WpConfigSecretsPathWriter()
		);
	}

	protected function resolveCandidate(): ?string {
		$this->resolverCalled = true;

		return $this->candidate;
	}

	protected function probeCandidate( string $candidate ): bool {
		$this->probeCalled = true;

		return ! $this->probeFails && parent::probeCandidate( $candidate );
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
			throw new \RAN\Secrets\SecretsStorageUnavailable( $this->healthFailureMessage );
		}

		return $this->healthy;
	}
}

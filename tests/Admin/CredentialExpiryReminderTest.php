<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/CredentialExpiryWordPressFunctions.php';

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryNoticeController;
use RAN\Admin\CredentialExpiryReminder;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use Tests\Admin\Support\ExpiryReminderProvider;
use Tests\Secrets\InMemorySiteKeyStore;
use Tests\Secrets\SecretsFileTestFactory;
use Tests\Support\InMemoryCredentialExpiryObservationStore;

// Direct local filesystem operations exercise the sidecar-backed reminder fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions
// Base64 mutation creates an authentication failure without exposing plaintext.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

final class CredentialExpiryReminderTest extends TestCase {

	private string $directory;
	private string $path;
	private SecretsFile $secrets;
	private InMemoryCredentialExpiryObservationStore $observations;
	private DateTimeImmutable $now;
	private ProviderRegistry $providers;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();

		$this->directory    = sys_get_temp_dir() . '/ran-booster-expiry-reminder-' . bin2hex( random_bytes( 8 ) );
		$this->path         = $this->directory . '/secrets.php';
		$this->now          = new DateTimeImmutable( '2026-07-23T12:00:00Z', new DateTimeZone( 'UTC' ) );
		$this->observations = new InMemoryCredentialExpiryObservationStore();
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$policies        = new ProviderSecretPolicyCatalog();
		$this->secrets   = SecretsFileTestFactory::create( $this->path, array(), $policies );
		$this->providers = new ProviderRegistry( array( new ExpiryReminderProvider() ), $policies );

		$GLOBALS['ran_booster_repository_admin_allowed']               = true;
		$GLOBALS['ran_booster_repository_admin_nonce_valid']           = true;
		$GLOBALS['ran_booster_repository_admin_user_id']               = 17;
		$GLOBALS['ran_booster_repository_admin_user_meta']             = array();
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = false;
	}

	protected function tearDown(): void {
		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_nonce_valid'],
			$GLOBALS['ran_booster_repository_admin_user_id'],
			$GLOBALS['ran_booster_repository_admin_user_meta'],
			$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'],
			$GLOBALS['ran_booster_admin_test_translations'],
			$GLOBALS['ran_booster_repository_admin_translations']
		);

		parent::tearDown();
	}

	public function test_exact_warning_urgent_and_expired_boundaries_use_the_injected_clock(): void {
		$this->credential( 'future', 'Future' );
		$this->credential( 'warning', 'Warning' );
		$this->credential( 'urgent', 'Urgent' );
		$this->credential( 'expired', 'Expired' );
		$this->provider_expiry( 'future', $this->now->modify( '+30 days +1 second' ) );
		$this->provider_expiry( 'warning', $this->now->modify( '+30 days' ) );
		$this->provider_expiry( 'urgent', $this->now->modify( '+7 days' ) );
		$this->provider_expiry( 'expired', $this->now );

		$reminders = $this->reminders();

		self::assertSame( 'future', $reminders->status( 'gh', $this->profile( 'future' ) )['stage'] );
		self::assertSame( 'warning', $reminders->status( 'gh', $this->profile( 'warning' ) )['stage'] );
		self::assertSame( 'urgent', $reminders->status( 'gh', $this->profile( 'urgent' ) )['stage'] );
		self::assertSame( 'expired', $reminders->status( 'gh', $this->profile( 'expired' ) )['stage'] );
		self::assertSame( array( 'expired', 'urgent', 'warning' ), array_column( $reminders->affected(), 'stage' ) );
	}

	public function test_provider_expiry_precedes_manual_date_and_missing_metadata_is_unknown(): void {
		$this->credential( 'precedence', 'Precedence' );
		$this->credential( 'unknown', 'Unknown' );
		$this->observations->set_manual_expiry( provider: 'gh', profile_id: 'precedence', expires_on: '2026-07-24' );
		$this->provider_expiry( 'precedence', $this->now->modify( '+40 days' ) );

		$status = $this->reminders()->status( 'gh', $this->profile( 'precedence' ) );

		self::assertSame( 'provider', $status['source'] );
		self::assertSame( 'future', $status['stage'] );
		self::assertSame( 'Expiry unknown', $this->reminders()->status( 'gh', $this->profile( 'unknown' ) )['badge_label'] );
	}

	public function test_reminder_and_notice_render_translated_administrator_copy(): void {
		$this->credential( 'translated', 'Translated credential' );
		$this->provider_expiry( 'translated', $this->now->modify( '+2 days' ) );
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']       = array(
			'Expires in %d day' . "\0" . 'Expires in %d days' => array(
				'single' => 'Expires in %d translated day',
				'plural' => 'Expires in %d translated days',
			),
			'%1$s (%2$s): %3$s.'    => '%1$s [%2$s]: %3$s!',
			'Review %s credentials' => 'Review translated %s credentials',
		);
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster'] = $GLOBALS['ran_booster_admin_test_translations']['ran-booster'];

		ob_start();
		( new CredentialExpiryNotice( $this->reminders() ) )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Translated credential [GitHub]: Expires in 2 translated days!', $html );
		self::assertStringContainsString( 'Review translated GitHub credentials', $html );
	}

	public function test_dismissal_is_per_user_and_reappears_at_the_next_severity_stage(): void {
		$this->credential( 'dismiss_me', 'Dismiss me' );
		$this->provider_expiry( 'dismiss_me', $this->now->modify( '+8 days' ) );
		$reminders  = $this->reminders();
		$controller = new CredentialExpiryNoticeController( $reminders );

		$result = $controller->handle();

		self::assertTrue( $result['success'] );
		$fingerprint = $reminders->fingerprint();
		self::assertSame(
			$fingerprint,
			$GLOBALS['ran_booster_repository_admin_user_meta'][17][ CredentialExpiryNotice::USER_META_KEY ]
		);
		self::assertFalse( ( new CredentialExpiryNotice( $reminders ) )->should_render() );

		$this->provider_expiry( 'dismiss_me', $this->now->modify( '+9 days' ) );
		self::assertTrue( ( new CredentialExpiryNotice( $reminders ) )->should_render() );
		self::assertNotSame( $fingerprint, $reminders->fingerprint() );

		$this->provider_expiry( 'dismiss_me', $this->now->modify( '+8 days' ) );
		$controller->handle();
		$GLOBALS['ran_booster_repository_admin_user_id'] = 18;
		self::assertTrue( ( new CredentialExpiryNotice( $reminders ) )->should_render() );

		$GLOBALS['ran_booster_repository_admin_user_id'] = 17;
		$this->advance_clock_one_day();
		self::assertTrue( ( new CredentialExpiryNotice( $reminders ) )->should_render() );
		self::assertNotSame( $fingerprint, $reminders->fingerprint() );
	}

	public function test_dismissal_rejects_unauthorized_invalid_nonce_and_persistence_failure(): void {
		$this->credential( 'dismiss_failures', 'Dismiss failures' );
		$this->provider_expiry( 'dismiss_failures', $this->now->modify( '+2 days' ) );
		$controller = new CredentialExpiryNoticeController( $this->reminders() );

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		self::assertSame( 403, $controller->handle()['status'] );
		$GLOBALS['ran_booster_repository_admin_allowed']     = true;
		$GLOBALS['ran_booster_repository_admin_nonce_valid'] = false;
		self::assertSame( 403, $controller->handle()['status'] );
		$GLOBALS['ran_booster_repository_admin_nonce_valid']           = true;
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = true;
		self::assertSame( 500, $controller->handle()['status'] );
	}

	public function test_notice_escapes_content_deep_links_and_renders_only_once_for_administrators(): void {
		$this->credential( 'replace_me', '<script>Replace me</script>' );
		$this->provider_expiry( 'replace_me', $this->now->modify( '+2 days' ) );
		$notice = new CredentialExpiryNotice( $this->reminders() );

		ob_start();
		$notice->render();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-credential-expiry-notice' ) );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;Replace me&lt;/script&gt;', $html );
		self::assertStringContainsString( 'tab=gh&amp;replace_credential=replace_me', $html );

		$GLOBALS['ran_booster_repository_admin_allowed'] = false;
		ob_start();
		( new CredentialExpiryNotice( $this->reminders() ) )->render();
		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function unreadable_sidecar_provider(): array {
		return array(
			array( 'key_only' ),
			array( 'ciphertext_only' ),
			array( 'malformed_envelope' ),
			array( 'truncated_envelope' ),
			array( 'authentication_failure' ),
		);
	}

	#[DataProvider( 'unreadable_sidecar_provider' )]
	public function test_unreadable_sidecar_renders_one_pathless_persistent_notice_without_changing_storage( string $state ): void {
		$this->credential( 'storage_failure', 'Storage failure' );
		$key_store  = SecretsFileTestFactory::key_store( $this->path );
		$key_before = $key_store->load();
		self::assertIsString( $key_before );

		$this->make_sidecar_unreadable( $state );
		$bytes_before = is_file( $this->path ) ? file_get_contents( $this->path ) : null;
		$key_before   = $key_store->load();
		$notice       = new CredentialExpiryNotice( $this->reminders() );

		ob_start();
		$notice->render();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-secrets-storage-notice' ) );
		self::assertStringContainsString( 'Credential-backed operations remain paused', $html );
		self::assertStringContainsString( 'Restore the matching sidecar and site key from the same backup', $html );
		self::assertStringContainsString( 'Review encrypted storage', $html );
		self::assertStringNotContainsString( 'button button-primary', $html );
		self::assertStringNotContainsString( 'is-dismissible', $html );
		self::assertStringNotContainsString( $this->directory, $html );
		self::assertStringNotContainsString( 'could not be authenticated', $html );
		self::assertStringNotContainsString( 'test-token-storage_failure', $html );
		self::assertFalse( $notice->should_load_dismissal_script() );
		self::assertSame( $bytes_before, is_file( $this->path ) ? file_get_contents( $this->path ) : null );
		self::assertSame( $key_before, $key_store->load() );
	}

	public function test_dismissal_endpoint_contains_unreadable_sidecar_without_changing_storage(): void {
		$this->credential( 'storage_failure', 'Storage failure' );
		$bytes_before = (string) file_get_contents( $this->path );
		$key_store    = SecretsFileTestFactory::key_store( $this->path );
		$key_before   = $key_store->load();
		$this->make_sidecar_unreadable( 'authentication_failure' );
		$tampered = (string) file_get_contents( $this->path );

		$result = ( new CredentialExpiryNoticeController( $this->reminders() ) )->handle();

		self::assertSame( 409, $result['status'] );
		self::assertStringContainsString( 'Restore the matching sidecar and site key', $result['data']['message'] );
		self::assertStringNotContainsString( $this->directory, $result['data']['message'] );
		self::assertStringNotContainsString( 'authenticated', $result['data']['message'] );
		self::assertSame( $tampered, file_get_contents( $this->path ) );
		self::assertNotSame( $bytes_before, $tampered );
		self::assertSame( $key_before, $key_store->load() );
	}

	private function reminders(): CredentialExpiryReminder {
		return new CredentialExpiryReminder(
			$this->providers,
			$this->secrets,
			$this->observations,
			fn (): DateTimeImmutable => $this->now
		);
	}

	private function credential( string $id, string $label ): void {
		$this->secrets->save_credential(
			'gh',
			$id,
			array(
				'label'         => $label,
				'kind'          => 'classic',
				'configuration' => array(),
			),
			'test-token-' . $id
		);
	}

	private function make_sidecar_unreadable( string $state ): void {
		if ( 'key_only' === $state ) {
			self::assertTrue( unlink( $this->path ) );
			return;
		}
		if ( 'ciphertext_only' === $state ) {
			InMemorySiteKeyStore::reset( $this->path );
			return;
		}
		if ( 'malformed_envelope' === $state ) {
			self::assertNotFalse( file_put_contents( $this->path, "{}\n" ) );
			self::assertTrue( chmod( $this->path, 0600 ) );
			return;
		}
		if ( 'truncated_envelope' === $state ) {
			self::assertNotFalse( file_put_contents( $this->path, '{"format":' ) );
			self::assertTrue( chmod( $this->path, 0600 ) );
			return;
		}

		$decoded = json_decode( (string) file_get_contents( $this->path ), true, 4, JSON_THROW_ON_ERROR );
		$bytes   = base64_decode( (string) $decoded['ciphertext'], true );
		self::assertIsString( $bytes );
		$bytes[0]              = chr( ord( $bytes[0] ) ^ 1 );
		$decoded['ciphertext'] = base64_encode( $bytes );
		self::assertNotFalse(
			file_put_contents(
				$this->path,
				json_encode( $decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n"
			)
		);
		self::assertTrue( chmod( $this->path, 0600 ) );
	}

	private function provider_expiry( string $id, DateTimeImmutable $expiry ): void {
		$this->observations->record_provider_expiry(
			provider: 'gh',
			profile_id: $id,
			report: CredentialExpiryReport::known( $expiry->format( 'Y-m-d\TH:i:s\Z' ) ),
			checked_at: $this->now->format( 'Y-m-d\TH:i:s\Z' )
		);
	}

	private function advance_clock_one_day(): void {
		$this->now = $this->now->modify( '+1 day' );
	}

	/** @return array<string, mixed> */
	private function profile( string $id ): array {
		$profile = $this->secrets->credential_profiles( 'gh' )[ $id ] ?? null;
		self::assertIsArray( $profile );

		return $profile;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

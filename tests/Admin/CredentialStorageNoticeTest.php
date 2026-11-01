<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/CredentialExpiryWordPressFunctions.php';
require_once __DIR__ . '/CredentialExpiryGlobalWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryReminder;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use RAN\Tests\Admin\Support\ExpiryReminderProvider;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;

final class CredentialStorageNoticeTest extends TestCase {

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_unclassified_failures_share_safe_guidance_for_site_and_network_admin(): void {
		require_once __DIR__ . '/CredentialStorageNoticeWordPressFunctions.php';
		foreach ( array( false, true ) as $network ) {
			$GLOBALS['ran_booster_expiry_test_network_admin'] = $network;
			$outputs = array();
			foreach ( array( 'Unsafe path /private/path-canary', 'Could not lock secret-canary', 'Authentication failed key-canary' ) as $failure ) {
				$secrets = $this->createMock( SecretsFile::class );
				$secrets->method( 'credential_profiles' )->willThrowException( new SecretsStorageUnavailable( $failure ) );
				$notice = new CredentialExpiryNotice(
					new CredentialExpiryReminder( new ProviderRegistry( array( new ExpiryReminderProvider() ) ), $secrets, new InMemoryCredentialExpiryObservationStore() )
				);
				ob_start();
				$notice->render();
				$outputs[] = (string) ob_get_clean();
			}
			self::assertSame( $outputs[0], $outputs[1] );
			self::assertSame( $outputs[0], $outputs[2] );
			self::assertStringContainsString( 'Booster cannot read saved credentials.', $outputs[0] );
			self::assertStringContainsString( 'Credential-backed operations remain paused.', $outputs[0] );
			self::assertStringContainsString( 'https://example.test/wp-admin/' . ( $network ? 'network/' : '' ) . 'admin.php?page=ran-booster-settings#ran-booster-storage', $outputs[0] );
			self::assertStringContainsString( 'Review Secure credential storage in Settings', $outputs[0] );
			foreach ( array( 'canary', 'backup', 'corrupt', 'lost', 'mismatch' ) as $claim ) {
				self::assertStringNotContainsString( $claim, $outputs[0] );
			}
		}
		unset( $GLOBALS['ran_booster_expiry_test_network_admin'] );
	}
}

<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderBoundWebhookDeliveryEvidenceReader;

final class ProviderTrustConformanceTest extends TestCase {

	public function testDeliveryEvidenceAdapterBindsTheProviderBeforeTheModuleReads(): void {
		$requested = null;
		$reader    = new ProviderBoundWebhookDeliveryEvidenceReader(
			ProviderCode::parse( 'gh' ),
			static function ( ProviderCode $provider ) use ( &$requested ): AuthenticatedWebhookDeliveryEvidence {
				$requested = $provider->value;

				return new AuthenticatedWebhookDeliveryEvidence( $provider, '2026-08-13 12:00:00', true );
			}
		);

		self::assertSame( '2026-08-13 12:00:00', $reader->latestAuthenticatedDelivery()?->receivedAt );
		self::assertSame( 'gh', $requested );
	}

	public function testDeliveryEvidenceAdapterRejectsCrossProviderEvidence(): void {
		$reader = new ProviderBoundWebhookDeliveryEvidenceReader(
			ProviderCode::parse( 'gh' ),
			static fn (): AuthenticatedWebhookDeliveryEvidence => new AuthenticatedWebhookDeliveryEvidence(
				ProviderCode::parse( 'bb' ),
				'2026-08-13 12:00:00',
				true
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'does not match its provider binding' );
		$reader->latestAuthenticatedDelivery();
	}

	public function testCredentialSurfacesDiscloseTheProviderTrustDecision(): void {
		$root = dirname( __DIR__, 2 );
		foreach (
			array(
				'views/provider.php',
				'views/provider/modals.php',
				'views/portability-review.php',
				'views/troubleshooting.php',
			) as $relativePath
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
			$source = file_get_contents( $root . '/' . $relativePath );
			self::assertIsString( $source );
			if ( 'views/provider.php' === $relativePath ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
				$source = file_get_contents( $root . '/RAN/Admin/ProviderSettingsPresenter.php' );
				self::assertIsString( $source );
			}
			self::assertStringContainsString(
				'does not authenticate a third-party publisher',
				$source,
				$relativePath
			);
		}
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderBoundWebhookDeliveryEvidenceReader;

final class ProviderTrustConformanceTest extends TestCase {

	public function test_delivery_evidence_adapter_binds_the_provider_before_the_module_reads(): void {
		$requested = null;
		$reader    = new ProviderBoundWebhookDeliveryEvidenceReader(
			ProviderCode::parse( 'gh' ),
			static function ( ProviderCode $provider ) use ( &$requested ): AuthenticatedWebhookDeliveryEvidence {
				$requested = $provider->value;

				return new AuthenticatedWebhookDeliveryEvidence( $provider, '2026-08-13 12:00:00', true );
			}
		);

		self::assertSame( '2026-08-13 12:00:00', $reader->latest_authenticated_delivery()?->received_at );
		self::assertSame( 'gh', $requested );
	}

	public function test_delivery_evidence_adapter_rejects_cross_provider_evidence(): void {
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
		$reader->latest_authenticated_delivery();
	}

	public function test_credential_surfaces_disclose_the_provider_trust_decision(): void {
		$root = dirname( __DIR__, 2 );
		foreach (
			array(
				'views/provider.php',
				'views/provider/modals.php',
				'views/portability-review.php',
				'views/troubleshooting.php',
			) as $relative_path
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
			$source = file_get_contents( $root . '/' . $relative_path );
			self::assertIsString( $source );
			if ( 'views/provider.php' === $relative_path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
				$source = file_get_contents( $root . '/RAN/Admin/ProviderSettingsPresenter.php' );
				self::assertIsString( $source );
			}
			self::assertStringContainsString(
				'does not authenticate a third-party publisher',
				$source,
				$relative_path
			);
		}
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\WebhookNormalizer;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;

/**
 * The shipped GitHub provider interpolates the delivery time (`Y-m-d H:i:s`) into its webhook readiness
 * message. The shared display screen must keep that exact shape, or the whole readiness row is lost.
 */
final class GitHubWebhookReadinessMessageTest extends TestCase {

	/** @return array<string, array{bool, string, string}> */
	public static function deliveries(): array {
		return array(
			'authenticated and unmatched' => array( false, ProviderDiagnosticResult::WARNING, 'gh.webhook.delivery_authenticated_unmatched' ),
			'authenticated and matched'   => array( true, ProviderDiagnosticResult::PASSED, 'gh.webhook.delivery_authenticated' ),
		);
	}

	#[DataProvider( 'deliveries' )]
	public function test_the_real_readiness_message_with_a_delivery_time_survives_the_display_screen( bool $matched, string $status, string $code ): void {
		$profiles = new class() implements ProviderWebhookProfileReader {
			public function has_webhook_profile(): bool {
				return true;
			}
		};
		$evidence = new class( $matched ) implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function __construct( private bool $matched ) {}

			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				return new AuthenticatedWebhookDeliveryEvidence( ProviderCode::parse( 'gh' ), '2026-08-13 12:00:00', $this->matched );
			}
		};

		$result = ( new WebhookNormalizer( $profiles, $evidence ) )->diagnose_webhook_readiness();

		self::assertSame( $status, $result->status );
		self::assertSame( $code, $result->code );
		self::assertStringContainsString( '2026-08-13 12:00:00 site time', $result->message );
	}
}

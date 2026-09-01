<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\DeploymentOutcomeMessage;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class DeploymentOutcomeMessageTest extends TestCase {

	public function testRateLimitMessageIsActionableAndContainsNoProviderEvidence(): void {
		$message = DeploymentOutcomeMessage::forCode( 'provider_rate_limited' );

		self::assertStringContainsString( 'rate limit', $message );
		self::assertStringContainsString( 'quota to reset', $message );
		self::assertStringNotContainsString( 'Authorization', $message );
	}

	public function testUnknownOutcomeUsesSafeFallbackCopy(): void {
		self::assertSame(
			'Booster recorded an unavailable deployment outcome.',
			DeploymentOutcomeMessage::forCode( 'provider said Authorization: Bearer secret-canary' )
		);
	}

	public function testArchiveLimitMessagesExplainTheTargetLocalRemedy(): void {
		$compressed = DeploymentOutcomeMessage::forCode( 'archive_compressed_too_large' );
		$expanded   = DeploymentOutcomeMessage::forCode( 'archive_expanded_too_large' );
		$invalid    = DeploymentOutcomeMessage::forCode( 'archive_limit_invalid' );

		self::assertStringContainsString( 'repository ZIP', $compressed );
		self::assertStringContainsString( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', $compressed );
		self::assertStringContainsString( 'expands beyond', $expanded );
		self::assertStringContainsString( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', $expanded );
		self::assertStringContainsString( '1 MiB and 512 MiB', $invalid );
		self::assertStringNotContainsString( 'Authorization', $compressed . $expanded . $invalid );
	}

	public function testPostconditionMessagesExplainTheSpecificSafeFailure(): void {
		self::assertStringContainsString( 'maintenance mode', DeploymentOutcomeMessage::forCode( 'maintenance_remaining' ) );
		self::assertStringContainsString( 'version', DeploymentOutcomeMessage::forCode( 'installed_version_mismatch' ) );
		self::assertStringContainsString( 'activation state', DeploymentOutcomeMessage::forCode( 'activation_state_changed' ) );
	}

	public function testPersistenceUncertainMessageDirectsTheOperatorToBothRecoverySurfaces(): void {
		$message = DeploymentOutcomeMessage::forCode( 'persistence_uncertain' );

		self::assertStringContainsString( 'activity', $message );
		self::assertStringContainsString( 'existing package settings', $message );
		self::assertStringContainsString( 'before retrying', $message );
	}

	public function testAlreadyManagedOutcomeDoesNotClaimTheRequestedBytesWereInstalled(): void {
		$message = DeploymentOutcomeMessage::forCode( 'already_managed' );

		self::assertStringContainsString( 'already manages', $message );
		self::assertStringNotContainsString( 'requested package bytes', $message );
	}
}

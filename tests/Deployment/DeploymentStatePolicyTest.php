<?php

declare(strict_types=1);

namespace Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentState;

final class DeploymentStatePolicyTest extends TestCase {

	public function testOnlyTheThreeTerminalStatesAreTerminal(): void {
		self::assertFalse( DeploymentState::QUEUED->isTerminal() );
		self::assertFalse( DeploymentState::RUNNING->isTerminal() );
		self::assertTrue( DeploymentState::SUCCEEDED->isTerminal() );
		self::assertTrue( DeploymentState::FAILED->isTerminal() );
		self::assertTrue( DeploymentState::NEEDS_ATTENTION->isTerminal() );
	}

	public function testPoliciesExpressManualAndWebhookAuthorityWithoutBooleanPtdDrift(): void {
		self::assertFalse( DeploymentPolicy::DISABLED->allowsManualMutation() );
		self::assertFalse( DeploymentPolicy::DISABLED->allowsWebhookMutation() );
		self::assertTrue( DeploymentPolicy::MANUAL->allowsManualMutation() );
		self::assertFalse( DeploymentPolicy::MANUAL->allowsWebhookMutation() );
		self::assertTrue( DeploymentPolicy::AUTOMATIC->allowsManualMutation() );
		self::assertTrue( DeploymentPolicy::AUTOMATIC->allowsWebhookMutation() );
	}

	public function testUnknownPersistedVocabularyIsRejected(): void {
		$this->expectException( InvalidArgumentException::class );
		DeploymentState::fromDatabase( 'pending' );
	}
}

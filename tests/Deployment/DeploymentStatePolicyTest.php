<?php

declare(strict_types=1);

namespace Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentState;

final class DeploymentStatePolicyTest extends TestCase {

	public function test_only_the_three_terminal_states_are_terminal(): void {
		self::assertFalse( DeploymentState::QUEUED->is_terminal() );
		self::assertFalse( DeploymentState::RUNNING->is_terminal() );
		self::assertTrue( DeploymentState::SUCCEEDED->is_terminal() );
		self::assertTrue( DeploymentState::FAILED->is_terminal() );
		self::assertTrue( DeploymentState::NEEDS_ATTENTION->is_terminal() );
	}

	public function test_policies_express_manual_and_webhook_authority_without_boolean_ptd_drift(): void {
		self::assertFalse( DeploymentPolicy::DISABLED->allows_manual_mutation() );
		self::assertFalse( DeploymentPolicy::DISABLED->allows_webhook_mutation() );
		self::assertTrue( DeploymentPolicy::MANUAL->allows_manual_mutation() );
		self::assertFalse( DeploymentPolicy::MANUAL->allows_webhook_mutation() );
		self::assertTrue( DeploymentPolicy::AUTOMATIC->allows_manual_mutation() );
		self::assertTrue( DeploymentPolicy::AUTOMATIC->allows_webhook_mutation() );
	}

	public function test_unknown_persisted_vocabulary_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		DeploymentState::from_database( 'pending' );
	}
}

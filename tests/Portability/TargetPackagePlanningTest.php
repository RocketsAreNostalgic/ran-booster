<?php

declare(strict_types=1);

namespace Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;

#[CoversClass( BlueprintPlanItem::class )]
#[CoversClass( TargetPackageAction::class )]
#[CoversClass( TargetPackageReason::class )]
final class TargetPackagePlanningTest extends TestCase {
	public function test_contract_uses_only_the_five_actions_and_twelve_reasons(): void {
		self::assertSame(
			array( 'install', 'adopt', 'managed', 'protected', 'blocked' ),
			array_column( TargetPackageAction::cases(), 'value' )
		);
		self::assertSame(
			array(
				'none',
				'already_managed',
				'management_conflict',
				'stale_management',
				'malformed_management',
				'credential_required',
				'local_secret_store_unavailable',
				'repository_access_failed',
				'repository_identity_mismatch',
				'destination_conflict',
				'provider_unavailable',
				'provider_temporarily_unavailable',
			),
			array_column( TargetPackageReason::cases(), 'value' )
		);
		self::assertSame( 'Managed by Booster', TargetPackageReason::ALREADY_MANAGED->message() );
	}

	#[DataProvider( 'valid_pairs' )]
	public function test_plan_item_accepts_valid_action_reason_pairs( TargetPackageAction $action, TargetPackageReason $reason ): void {
		$item = new BlueprintPlanItem( $this->package(), $action, $reason );

		self::assertSame( $action, $item->action );
		self::assertSame( $reason, $item->reason );
	}

	/** @return iterable<string, array{TargetPackageAction, TargetPackageReason}> */
	public static function valid_pairs(): iterable {
		yield 'install' => array( TargetPackageAction::INSTALL, TargetPackageReason::NONE );
		yield 'adopt' => array( TargetPackageAction::ADOPT, TargetPackageReason::NONE );
		yield 'managed' => array( TargetPackageAction::MANAGED, TargetPackageReason::ALREADY_MANAGED );

		foreach ( array( TargetPackageReason::MANAGEMENT_CONFLICT, TargetPackageReason::STALE_MANAGEMENT, TargetPackageReason::MALFORMED_MANAGEMENT ) as $reason ) {
			yield $reason->value => array( TargetPackageAction::PROTECTED, $reason );
		}

		foreach ( array_slice( TargetPackageReason::cases(), 5 ) as $reason ) {
			yield $reason->value => array( TargetPackageAction::BLOCKED, $reason );
		}
	}

	#[DataProvider( 'invalid_pairs' )]
	public function test_plan_item_rejects_invalid_action_reason_pairs( TargetPackageAction $action, TargetPackageReason $reason ): void {
		$this->expectException( InvalidArgumentException::class );

		new BlueprintPlanItem( $this->package(), $action, $reason );
	}

	/** @return iterable<string, array{TargetPackageAction, TargetPackageReason}> */
	public static function invalid_pairs(): iterable {
		yield 'install with block' => array( TargetPackageAction::INSTALL, TargetPackageReason::CREDENTIAL_REQUIRED );
		yield 'adopt with management' => array( TargetPackageAction::ADOPT, TargetPackageReason::ALREADY_MANAGED );
		yield 'managed without reason' => array( TargetPackageAction::MANAGED, TargetPackageReason::NONE );
		yield 'protected with operational reason' => array( TargetPackageAction::PROTECTED, TargetPackageReason::DESTINATION_CONFLICT );
		yield 'blocked without reason' => array( TargetPackageAction::BLOCKED, TargetPackageReason::NONE );
	}

	private function package(): BlueprintPackage {
		return new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', '123', 'owner/repository', 'main', null );
	}
}

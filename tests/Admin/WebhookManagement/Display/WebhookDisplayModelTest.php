<?php

declare( strict_types = 1 );

namespace RAN\Tests\Admin\WebhookManagement\Display;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;
use ReflectionClass;
use ReflectionMethod;

require_once __DIR__ . '/WebhookDisplayModelWordPressFunctions.php';

final class WebhookDisplayModelTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_webhook_display_test_translations'] );
	}

	public function test_it_translates_known_fixed_core_notice_copy(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			'The provider rejected the webhook setup request. No remote hook was established.' => 'Le fournisseur a refuse la configuration du webhook. Aucun webhook distant n a ete etabli.',
		);

		self::assertSame(
			'Le fournisseur a refuse la configuration du webhook. Aucun webhook distant n a ete etabli.',
			$this->display()->notice( 'setup_failed' )
		);
	}

	public function test_it_translates_formatted_recovery_copy_without_changing_reference_ids(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			'Provider state may have changed, but the current webhook-management record was not overwritten. Inspect provider hook reference %1$s and Core signing profile %2$s before retrying.' => 'Profil Core %2$s et reference de webhook %1$s a inspecter.',
		);

		self::assertSame(
			'Profil Core profile-abc et reference de webhook hook-123 a inspecter.',
			$this->display()->notice(
				'recovery_record_failed',
				array(
					'hook_id'    => 'hook-123',
					'profile_id' => 'profile-abc',
				)
			)
		);
	}

	public function test_it_translates_default_core_copy_without_changing_provider_remediation(): void {
		$default     = 'Webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.';
		$remediation = 'Provider remediation: retain result_code=opaque and https://provider.example/hooks/123 exactly.';
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			$default => 'Le resultat de l operation webhook doit etre verifie dans l etat enregistre.',
		);

		self::assertSame( 'Le resultat de l operation webhook doit etre verifie dans l etat enregistre.', $this->display()->notice( 'unknown_result' ) );
		self::assertSame( $remediation, $this->display()->notice( 'unknown_result', null, $remediation ) );
	}

	public function test_recorded_history_keeps_its_status_and_timestamp_beside_a_current_warning(): void {
		$method  = new ReflectionMethod( WebhookDisplayModel::class, 'history_details' );
		$details = array_column( $method->invoke( $this->display(), 'profile_revision_stale', $this->record() ), null, 'key' );

		self::assertSame( 'Configured at last check', $details['core:webhook-recorded-status']['value'] );
		self::assertTrue( $details['core:webhook-recorded-status']['recorded'] );
		self::assertSame( 'profile_revision_stale', $details['core:webhook-recorded-status']['state'] );
		self::assertSame( 'Signing secret changed; webhook update required', $details['core:webhook-current-warning']['value'] );
		self::assertSame( 'Historical only; not live readiness or a signed delivery', $details['core:webhook-observation']['value'] );
		self::assertSame( '2026-08-20T01:02:03Z', $details['core:webhook-last-checked']['value'] );
		self::assertSame( '2026-08-20T01:02:03Z', $details['core:webhook-last-checked']['datetime'] );
	}

	public function test_missing_history_does_not_invent_a_record_or_timestamp(): void {
		$method  = new ReflectionMethod( WebhookDisplayModel::class, 'history_details' );
		$details = array_column( $method->invoke( $this->display(), 'not_configured', null ), null, 'key' );

		self::assertFalse( $details['core:webhook-recorded-status']['recorded'] );
		self::assertSame( 'Managed hook not yet set', $details['core:webhook-recorded-status']['value'] );
		self::assertSame( 'No historical observation', $details['core:webhook-observation']['value'] );
		self::assertSame( 'Never', $details['core:webhook-last-checked']['value'] );
		self::assertSame( '', $details['core:webhook-last-checked']['datetime'] );
		self::assertArrayNotHasKey( 'core:webhook-current-warning', $details );
	}

	public function test_historical_rows_do_not_read_current_provider_state(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		foreach ( array( 'readiness', 'target', 'credential_choices', 'webhook_profile_choices', 'profile' ) as $method ) {
			$facade->expects( self::never() )->method( $method );
		}
		$record = $this->record();
		$store  = $this->createMock( InstallationStore::class );
		$store->method( 'all' )->willReturn( array( $record->storage_key() => $record ) );
		$display = new WebhookDisplayModel( $facade, $store );
		$rows    = array(
			'package' => array(
				'details' => array(),
				'actions' => array(),
			),
		);
		$result  = $display->enrich_historical_rows( $rows, 'gh', array( 'package' => array( 'repository_id' => 'repo-42' ) ) );
		$details = array_column( $result['package']['details'], null, 'key' );

		self::assertSame( 'Configured at last check', $details['core:webhook-recorded-status']['value'] );
		self::assertSame( 'Historical only; not live readiness or a signed delivery', $details['core:webhook-observation']['value'] );
		self::assertSame( '2026-08-20T01:02:03Z', $details['core:webhook-last-checked']['datetime'] );
		self::assertArrayNotHasKey( 'core:webhook-current-warning', $details );
	}

	private function record(): InstallationRecord {
		return new InstallationRecord( 'gh', 'repo-42', 'owner/example', '77', 'credential_1', 'wh_0123456789abcdef01234567', 'repository', 1, 'created', 'https://hooks.example.test/webhook', 'configured', '2026-08-19T23:01:02Z', '2026-08-20T01:02:03Z' );
	}

	private function display(): WebhookDisplayModel {
		return ( new ReflectionClass( WebhookDisplayModel::class ) )->newInstanceWithoutConstructor();
	}
}

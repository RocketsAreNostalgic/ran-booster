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
use RAN\AddOn\WebhookAssistance\AssistanceTarget;

require_once __DIR__ . '/WebhookDisplayModelWordPressFunctions.php';
require_once dirname( __DIR__, 3 ) . '/Support/PackageViewWordPressFunctions.php';

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
			'The Git provider rejected the webhook setup request. No remote hook was established.' => 'Le fournisseur a refuse la configuration du webhook. Aucun webhook distant n a ete etabli.',
		);

		self::assertSame(
			'Le fournisseur a refuse la configuration du webhook. Aucun webhook distant n a ete etabli.',
			$this->display()->notice( 'setup_failed' )
		);
	}

	public function test_it_translates_formatted_recovery_copy_without_changing_reference_ids(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			'Git provider state may have changed, but the current webhook-management record was not overwritten. Inspect Git provider hook reference %1$s and Core signing profile %2$s before retrying.' => 'Profil Core %2$s et reference de webhook %1$s a inspecter.',
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

	public function test_new_installation_keeps_all_fixed_controls_but_only_allows_setup_with_credentials(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			'Set up' => 'Configurer',
		);
		foreach ( array( false, true ) as $has_credential ) {
			$facade = $this->createMock( WebhookAssistanceFacade::class );
			$facade->method( 'target' )->willReturn(
				new AssistanceTarget(
					'fixture-provider',
					'1234',
					'owner/example',
					'Example',
					array( 'example/example.php' ),
					array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'https://example.test/webhook'
				)
			);
			$facade->method( 'credential_choices' )->willReturn(
				$has_credential ? array(
					array(
						'id'         => 'credential-1',
						'label'      => 'Saved credential',
						'kind'       => 'token',
						'destroy_on' => null,
					),
				) : array()
			);
			$store = $this->createMock( InstallationStore::class );
			$store->method( 'find' )->willReturn( null );
			$display = new WebhookDisplayModel( $facade, $store );
			$panel   = $display->panel( 'fixture-provider', 'Fixture Forge', '1234', 'https://example.test/settings', null, null, true );

			self::assertNotNull( $panel );
			self::assertSame( array( 'setup', 'check', 'test', 'remove' ), array_column( $panel['operations'], 'key' ) );
			self::assertSame( array( ! $has_credential, true, true, true ), array_column( $panel['operations'], 'disabled' ) );
			$recovery = $display->panel(
				'fixture-provider',
				'<Fixture Forge>',
				'1234',
				'https://example.test/settings',
				'recovery_record_failed',
				array(
					'hook_id'    => '<hook>',
					'profile_id' => '<profile>',
				),
				true
			);
			self::assertNotNull( $recovery );
			self::assertIsArray( $recovery['recovery_warning'] );
			self::assertArrayHasKey( 'details', $recovery['recovery_warning'] );
			self::assertIsArray( $recovery['result'] );
			self::assertArrayHasKey( 'details', $recovery['result'] );
			self::assertStringContainsString( 'Setup is disabled on this recovery view', $recovery['recovery_warning']['message'] );
			self::assertSame( array(), $recovery['recovery_warning']['details'] ?? null );
			self::assertSame( array( 'Git provider hook reference: <hook>', 'Core signing profile: <profile>' ), $recovery['result']['details'] ?? null );
			self::assertStringContainsString( 'record was not overwritten', $recovery['result']['message'] );
			$other_recovery = $display->panel(
				'fixture-provider',
				'<Fixture Forge>',
				'1234',
				'https://example.test/settings',
				'operation_failed',
				array(
					'hook_id'    => '<hook>',
					'profile_id' => '<profile>',
				),
				true
			);
			self::assertNotNull( $other_recovery );
			self::assertIsArray( $other_recovery['recovery_warning'] );
			self::assertArrayHasKey( 'details', $other_recovery['recovery_warning'] );
			self::assertStringContainsString( '<Fixture Forge>', $other_recovery['recovery_warning']['details'][0] ?? '' );
			self::assertSame( 'Git provider hook reference: <hook>', $other_recovery['recovery_warning']['details'][1] ?? null );
			self::assertSame( 'Core signing profile: <profile>', $other_recovery['recovery_warning']['details'][2] ?? null );
			self::assertSame( array( true, true, true, true ), array_column( $recovery['operations'], 'disabled' ) );
			self::assertSame( 'Configurer', $panel['operations'][0]['label'] );
			foreach ( $panel['operations'] as $operation ) {
				self::assertStringContainsString( 'nonce-for-' . hash( 'sha256', 'ran_booster_repository_webhook_' . $operation['key'] . '_fixture-provider_1234' ), $operation['url'] );
			}
		}
	}

	public function test_the_four_fixed_slots_share_the_first_slot_between_setup_and_repair(): void {
		$method = new ReflectionMethod( WebhookDisplayModel::class, 'operation_models' );
		$labels = static fn ( array $operations ): array => array_column( $operations, 'label' );
		$keys   = static fn ( array $operations ): array => array_column( $operations, 'key' );
		$off    = static fn ( array $operations ): array => array_column( $operations, 'disabled' );

		$none = $method->invoke( $this->display(), array(), 'gh', 'GitHub', '1', false );
		self::assertSame( array( 'Set up', 'Validate settings', 'Test ping', 'Remove' ), $labels( $none ) );
		self::assertSame( array( 'setup', 'check', 'test', 'remove' ), $keys( $none ) );
		self::assertSame( array( true, true, true, true ), $off( $none ) );

		$setup = $method->invoke( $this->display(), array( 'setup' => true ), 'gh', 'GitHub', '1', false );
		self::assertSame( array( false, true, true, true ), $off( $setup ) );
		self::assertTrue( $setup[0]['primary'] );

		$all    = array(
			'reconfigure' => true,
			'check'       => true,
			'test'        => true,
			'remove'      => true,
		);
		$repair = $method->invoke( $this->display(), $all, 'gh', 'GitHub', '1', true );
		self::assertSame( array( 'Repair', 'Validate settings', 'Test ping', 'Remove' ), $labels( $repair ) );
		self::assertSame( array( 'reconfigure', 'check', 'test', 'remove' ), $keys( $repair ) );
		self::assertSame( array( false, false, false, false ), $off( $repair ) );
		self::assertSame( array( true, false, false, false ), array_column( $repair, 'primary' ) );
		self::assertSame( array( false, false, false, true ), array_column( $repair, 'destructive' ) );

		unset( $all['reconfigure'] );
		$in_sync = $method->invoke( $this->display(), $all, 'gh', 'GitHub', '1', true );
		self::assertSame( 'Repair', $in_sync[0]['label'] );
		self::assertSame( array( true, false, false, false ), $off( $in_sync ) );

		self::assertSame( 'Create the webhook on GitHub.', $none[0]['description'] );
		self::assertSame( 'Make the webhook on GitHub match this site\'s settings.', $repair[0]['description'] );
		self::assertSame( 'Compare the webhook on GitHub with this site\'s settings. Nothing is sent.', $repair[1]['description'] );
		self::assertSame( 'Ask GitHub to send a ping. Delivery counts only when it arrives.', $repair[2]['description'] );
		self::assertSame( 'Delete the webhook from GitHub and clear its local record.', $repair[3]['description'] );
		self::assertSame( 'Remove this webhook from the repository? Booster will delete the webhook on GitHub and clear its local record.', $repair[3]['confirm'] );
		self::assertSame( array( null, null, null ), array( $repair[0]['confirm'], $repair[1]['confirm'], $repair[2]['confirm'] ) );
	}

	private function display(): WebhookDisplayModel {
		return ( new ReflectionClass( WebhookDisplayModel::class ) )->newInstanceWithoutConstructor();
	}
}

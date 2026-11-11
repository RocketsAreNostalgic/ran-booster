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
		self::assertTrue( $details['core:webhook-recorded-status']['identified'] );
		self::assertSame( 'profile_revision_stale', $details['core:webhook-recorded-status']['state'] );
		self::assertSame( 'Signing secret changed; webhook update required', $details['core:webhook-current-warning']['value'] );
		self::assertSame( 'Historical only', $details['core:webhook-observation']['value'] );
		self::assertSame( '2026-08-20T01:02:03Z', $details['core:webhook-last-checked']['value'] );
		self::assertSame( '2026-08-20T01:02:03Z', $details['core:webhook-last-checked']['datetime'] );
	}

	public function test_missing_history_does_not_invent_a_record_or_timestamp(): void {
		$method  = new ReflectionMethod( WebhookDisplayModel::class, 'history_details' );
		$details = array_column( $method->invoke( $this->display(), 'not_configured', null ), null, 'key' );

		self::assertFalse( $details['core:webhook-recorded-status']['recorded'] );
		self::assertFalse( $details['core:webhook-recorded-status']['identified'] );
		self::assertSame( 'Managed hook not yet set', $details['core:webhook-recorded-status']['value'] );
		self::assertSame( 'None', $details['core:webhook-observation']['value'] );
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
		self::assertSame( 'Historical only', $details['core:webhook-observation']['value'] );
		self::assertSame( '2026-08-20T01:02:03Z', $details['core:webhook-last-checked']['datetime'] );
		self::assertArrayNotHasKey( 'core:webhook-current-warning', $details );
	}

	public function test_manual_state_needs_no_record_and_a_verified_delivery(): void {
		$calls  = array();
		$reader = static function ( string $provider, array $ids ) use ( &$calls ): array {
			$calls[] = array( $provider, $ids );

			return array( 'repo-42' => '2026-08-21 09:30:15' );
		};

		self::assertSame( '2026-08-21T09:30:15Z', $this->display_with_deliveries( $reader, null )->manual_delivery_time( 'gh', 'repo-42' ) );
		self::assertSame( array( array( 'gh', array( 'repo-42' ) ) ), $calls );
		// Only a verified delivery for that repository counts, and a recorded hook keeps its own state.
		self::assertNull( $this->display_with_deliveries( static fn (): array => array(), null )->manual_delivery_time( 'gh', 'repo-42' ) );
		self::assertNull( $this->display_with_deliveries( static fn (): array => array( 'other' => '2026-08-21 09:30:15' ), null )->manual_delivery_time( 'gh', 'repo-42' ) );
		$calls = array();
		self::assertNull( $this->display_with_deliveries( $reader, $this->record() )->manual_delivery_time( 'gh', 'repo-42' ) );
		self::assertSame( array(), $calls, 'A recorded webhook never consults delivery evidence.' );
		self::assertNull( $this->display_with_deliveries( static fn (): array => array( 'repo-42' => 'not a time' ), null )->manual_delivery_time( 'gh', 'repo-42' ) );
		self::assertNull(
			$this->display_with_deliveries(
				static function (): array {
					throw new \RuntimeException( 'storage' );
				},
				null
			)->manual_delivery_time(
				'gh',
				'repo-42'
			)
		);
		self::assertNull( ( new WebhookDisplayModel( $this->never_contacting_facade(), $this->store_returning( null ) ) )->manual_delivery_time( 'gh', 'repo-42' ) );
	}

	public function test_manual_notice_is_an_info_notice_with_heading_and_the_delivery_sentence(): void {
		self::assertSame(
			array(
				'class'   => 'notice-info',
				'heading' => 'Signed delivery received',
				'message' => 'Last signed delivery received on 2026-08-21T09:30:15Z. Booster has no webhook record for this repository, so it cannot validate one.',
			),
			WebhookDisplayModel::manual_notice( '2026-08-21T09:30:15Z' )
		);
	}

	public function test_manual_history_row_reads_manual_webhook_and_other_rows_are_unchanged(): void {
		$method  = new ReflectionMethod( WebhookDisplayModel::class, 'history_details' );
		$display = $this->display();
		$manual  = array_column( $method->invoke( $display, 'not_configured', null, '2026-08-21T09:30:15Z' ), null, 'key' );
		$plain   = array_column( $method->invoke( $display, 'not_configured', null, null ), null, 'key' );

		self::assertSame( 'Signed delivery received', $manual['core:webhook-recorded-status']['value'] );
		self::assertSame( 'ok', $manual['core:webhook-recorded-status']['tone'] );
		self::assertFalse( $manual['core:webhook-recorded-status']['recorded'] );
		self::assertFalse( $manual['core:webhook-recorded-status']['identified'] );
		self::assertSame( 'Never', $manual['core:webhook-last-checked']['value'] );
		self::assertSame( 'Managed hook not yet set', $plain['core:webhook-recorded-status']['value'] );
		self::assertSame( 'warning', $plain['core:webhook-recorded-status']['tone'] );
		// A recorded hook ignores any supplied manual time.
		$recorded = array_column( $method->invoke( $display, 'configured', $this->record(), '2026-08-21T09:30:15Z' ), null, 'key' );
		self::assertSame( 'Configured at last check', $recorded['core:webhook-recorded-status']['value'] );
	}

	public function test_enrich_rows_marks_only_unrecorded_repositories_with_verified_deliveries_as_manual(): void {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->expects( self::never() )->method( 'target' );
		$facade->method( 'readiness' )->willReturn(
			new \RAN\AddOn\WebhookAssistance\AssistanceReadiness(
				array(),
				'https://hooks.example.test/webhook',
				array(
					array(
						'provider_code' => 'gh',
						'repository_id' => 'repo-42',
						'eligible'      => true,
					),
					array(
						'provider_code' => 'gh',
						'repository_id' => 'repo-7',
						'eligible'      => true,
					),
					array(
						'provider_code' => 'gh',
						'repository_id' => 'repo-9',
						'eligible'      => true,
					),
				)
			)
		);
		$record = $this->record();
		$store  = $this->createMock( InstallationStore::class );
		$store->method( 'all' )->willReturn( array( $record->storage_key() => $record ) );
		$requested   = array();
		$display     = new WebhookDisplayModel(
			$facade,
			$store,
			static function ( string $provider, array $ids ) use ( &$requested ): array {
				$requested[] = array_merge( array( $provider ), $ids );

				return array( 'repo-7' => '2026-08-21 09:30:15' );
			}
		);
		$rows        = array(
			'repo-42' => array( 'details' => array() ),
			'repo-7'  => array( 'details' => array() ),
			'repo-9'  => array( 'details' => array() ),
		);
		$projections = array(
			'repo-42' => array( 'repository_id' => 'repo-42' ),
			'repo-7'  => array( 'repository_id' => 'repo-7' ),
			'repo-9'  => array( 'repository_id' => 'repo-9' ),
		);
		$result      = $display->enrich_rows( $rows, 'gh', 'GitHub', 'https://github.test/', $projections, 'https://example.test/return' );
		$status      = static fn ( string $key ): string => array_column( $result[ $key ]['details'], null, 'key' )['core:webhook-recorded-status']['value'];

		self::assertSame( array( array( 'gh', 'repo-7', 'repo-9' ) ), $requested, 'One bounded read covers only repositories without a record.' );
		self::assertSame( 'Configured at last check', $status( 'repo-42' ) );
		self::assertSame( 'Signed delivery received', $status( 'repo-7' ) );
		self::assertSame( 'Managed hook not yet set', $status( 'repo-9' ) );
	}

	public function test_manual_state_changes_only_the_disabled_reason_not_availability(): void {
		$panel_for = function ( ?\Closure $reader ): array {
			$facade = $this->createMock( WebhookAssistanceFacade::class );
			$facade->method( 'target' )->willReturn(
				new AssistanceTarget(
					'gh',
					'repo-42',
					'owner/example',
					'Example',
					array(),
					array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'https://example.test/webhook'
				)
			);
			$facade->method( 'credential_choices' )->willReturn(
				array(
					array(
						'id'         => 'credential-1',
						'label'      => 'Saved credential',
						'kind'       => 'token',
						'destroy_on' => null,
					),
				)
			);
			$panel = ( new WebhookDisplayModel( $facade, $this->store_returning( null ), $reader ) )->panel( 'gh', 'GitHub', 'repo-42', 'https://example.test/settings', null, null, true );
			self::assertNotNull( $panel );

			return $panel['operations'];
		};
		$manual    = $panel_for( static fn (): array => array( 'repo-42' => '2026-08-21 09:30:15' ) );
		$plain     = $panel_for( static fn (): array => array() );

		self::assertSame( array_column( $plain, 'disabled' ), array_column( $manual, 'disabled' ) );
		self::assertSame( array( false, true, true ), array_column( $manual, 'disabled' ) );
		self::assertSame( 'Booster has no record of this webhook.', $manual[1]['disabled_reason'] );
		self::assertSame( 'Booster has no record of this webhook.', $manual[2]['disabled_reason'] );
		self::assertSame( 'Set up the webhook to enable these actions.', $plain[1]['disabled_reason'] );
	}

	public function test_a_webhook_removed_through_booster_is_not_claimed_as_a_manual_webhook(): void {
		$record     = $this->record();
		$deliveries = array( 'repo-42' => '2026-08-21 09:30:15' );
		$display    = $this->lifecycle_display( $record, $deliveries );

		self::assertNull( $display->manual_delivery_time( 'gh', 'repo-42' ), 'A recorded managed hook keeps its own state.' );
		$managed = $display->panel( 'gh', 'GitHub', 'repo-42', 'https://example.test/settings', null, null, true );
		self::assertNotNull( $managed );
		self::assertNull( $managed['manual_notice'] );

		// Remove through Booster: the record is deleted, the earlier delivery stays in the history.
		$record = null;
		$panel  = $display->panel( 'gh', 'GitHub', 'repo-42', 'https://example.test/settings', null, null, true );
		self::assertNotNull( $panel );
		$shown = array( WebhookDisplayModel::manual_status_label() );
		array_walk_recursive(
			$panel,
			static function ( mixed $value ) use ( &$shown ): void {
				if ( is_string( $value ) ) {
					$shown[] = $value;
				}
			}
		);
		$shown = implode( "\n", $shown );

		self::assertSame( '2026-08-21T09:30:15Z', $display->manual_delivery_time( 'gh', 'repo-42' ) );
		self::assertSame(
			array(
				'class'   => 'notice-info',
				'heading' => 'Signed delivery received',
				'message' => 'Last signed delivery received on 2026-08-21T09:30:15Z. Booster has no webhook record for this repository, so it cannot validate one.',
			),
			$panel['manual_notice']
		);
		self::assertStringNotContainsString( 'Manual webhook', $shown );
		self::assertStringNotContainsString( 'did not set up', $shown );
		self::assertStringNotContainsString( 'did not create', $shown );
	}

	public function test_a_never_managed_repository_with_a_signed_delivery_keeps_the_delivery_evidence_state(): void {
		$record     = null;
		$deliveries = array( 'repo-42' => '2026-08-21 09:30:15' );
		$panel      = $this->lifecycle_display( $record, $deliveries )->panel( 'gh', 'GitHub', 'repo-42', 'https://example.test/settings', null, null, true );
		self::assertNotNull( $panel );

		self::assertSame( WebhookDisplayModel::manual_notice( '2026-08-21T09:30:15Z' ), $panel['manual_notice'] );
		self::assertSame( array( false, true, true ), array_column( $panel['operations'], 'disabled' ) );
		self::assertSame( 'Booster has no record of this webhook.', $panel['operations'][1]['disabled_reason'] );
	}

	public function test_a_delivery_after_a_booster_removal_shows_the_newer_delivery_evidence(): void {
		$record     = $this->record();
		$deliveries = array( 'repo-42' => '2026-08-21 09:30:15' );
		$display    = $this->lifecycle_display( $record, $deliveries );
		$record     = null;
		self::assertSame( '2026-08-21T09:30:15Z', $display->manual_delivery_time( 'gh', 'repo-42' ) );

		// A hook still delivering after the removal is real evidence; the newest delivery is shown.
		$deliveries = array( 'repo-42' => '2026-09-02 08:00:00' );
		$panel      = $display->panel( 'gh', 'GitHub', 'repo-42', 'https://example.test/settings', null, null, true );
		self::assertNotNull( $panel );

		self::assertSame( WebhookDisplayModel::manual_notice( '2026-09-02T08:00:00Z' ), $panel['manual_notice'] );
	}

	/**
	 * Store and delivery reader follow the caller's variables, so a test can delete the record or add a delivery.
	 *
	 * @param array<string, string> $deliveries
	 */
	private function lifecycle_display( ?InstallationRecord &$record, array &$deliveries ): WebhookDisplayModel {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		$facade->method( 'target' )->willReturn(
			new AssistanceTarget(
				'gh',
				'repo-42',
				'owner/example',
				'Example',
				array(),
				array(
					'automatic' => 0,
					'manual'    => 1,
					'disabled'  => 0,
				),
				'https://example.test/webhook'
			)
		);
		$facade->method( 'credential_choices' )->willReturn(
			array(
				array(
					'id'         => 'credential-1',
					'label'      => 'Saved credential',
					'kind'       => 'token',
					'destroy_on' => null,
				),
			)
		);
		$store = $this->createMock( InstallationStore::class );
		$store->method( 'find' )->willReturnCallback(
			static function () use ( &$record ): ?InstallationRecord {
				return $record;
			}
		);
		$store->method( 'all' )->willReturnCallback(
			static function () use ( &$record ): array {
				return null === $record ? array() : array( $record->storage_key() => $record );
			}
		);

		return new WebhookDisplayModel(
			$facade,
			$store,
			static function () use ( &$deliveries ): array {
				return $deliveries;
			}
		);
	}

	public function test_documentation_says_a_hand_made_webhook_cannot_be_validated(): void {
		$sentence = 'A webhook you created yourself cannot be validated by Booster; a verified delivery in Activity shows it works.';
		$body     = implode( ' ', array_column( $this->display()->documentation( 'GitHub' ), 'body' ) );

		self::assertStringContainsString( $sentence, $body );
		self::assertStringContainsString( $sentence, (string) file_get_contents( dirname( __DIR__, 4 ) . '/views/documentation.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local view source.
	}

	/** @param \Closure(string, list<string>): array<string, string> $reader */
	private function display_with_deliveries( \Closure $reader, ?InstallationRecord $record ): WebhookDisplayModel {
		return new WebhookDisplayModel( $this->never_contacting_facade(), $this->store_returning( $record ), $reader );
	}

	private function never_contacting_facade(): WebhookAssistanceFacade {
		$facade = $this->createMock( WebhookAssistanceFacade::class );
		foreach ( array( 'readiness', 'target', 'credential_choices', 'webhook_profile_choices', 'profile' ) as $method ) {
			$facade->expects( self::never() )->method( $method );
		}

		return $facade;
	}

	private function store_returning( ?InstallationRecord $record ): InstallationStore {
		$store = $this->createMock( InstallationStore::class );
		$store->method( 'find' )->willReturn( $record );
		$store->method( 'all' )->willReturn( null === $record ? array() : array( $record->storage_key() => $record ) );

		return $store;
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
			self::assertSame( array( 'setup', 'validate', 'remove' ), array_column( $panel['operations'], 'key' ) );
			self::assertSame( array( ! $has_credential, true, true ), array_column( $panel['operations'], 'disabled' ) );
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
			self::assertSame( array( true, true, true ), array_column( $recovery['operations'], 'disabled' ) );
			self::assertSame( 'Configurer', $panel['operations'][0]['label'] );
			foreach ( $panel['operations'] as $operation ) {
				// Validate posts the Check nonce in its URL and the Test nonce as a companion field.
				self::assertStringContainsString( 'nonce-for-' . hash( 'sha256', 'ran_booster_repository_webhook_' . ( 'validate' === $operation['key'] ? 'check' : $operation['key'] ) . '_fixture-provider_1234' ), $operation['url'] );
				self::assertSame(
					'validate' === $operation['key'] ? array(
						'name'  => '_wpnonce_test',
						'value' => 'nonce-for-' . hash( 'sha256', 'ran_booster_repository_webhook_test_fixture-provider_1234' ),
					) : null,
					$operation['companion_nonce']
				);
			}
		}
	}

	public function test_the_three_fixed_slots_share_the_first_slot_between_setup_and_repair(): void {
		$method = new ReflectionMethod( WebhookDisplayModel::class, 'operation_models' );
		$labels = static fn ( array $operations ): array => array_column( $operations, 'label' );
		$keys   = static fn ( array $operations ): array => array_column( $operations, 'key' );
		$off    = static fn ( array $operations ): array => array_column( $operations, 'disabled' );

		$none = $method->invoke( $this->display(), array(), 'gh', 'GitHub', '1', false, array() );
		self::assertSame( array( 'Set up', 'Validate', 'Remove' ), $labels( $none ) );
		self::assertSame( array( 'setup', 'validate', 'remove' ), $keys( $none ) );
		self::assertSame( array( true, true, true ), $off( $none ) );

		$setup = $method->invoke( $this->display(), array( 'setup' => true ), 'gh', 'GitHub', '1', false, array() );
		self::assertSame( array( false, true, true ), $off( $setup ) );
		self::assertTrue( $setup[0]['primary'] );

		$all    = array(
			'reconfigure' => true,
			'check'       => true,
			'test'        => true,
			'remove'      => true,
		);
		$repair = $method->invoke( $this->display(), $all, 'gh', 'GitHub', '1', true, array() );
		self::assertSame( array( 'Repair', 'Validate', 'Remove' ), $labels( $repair ) );
		self::assertSame( array( 'reconfigure', 'validate', 'remove' ), $keys( $repair ) );
		self::assertSame( array( false, false, false ), $off( $repair ) );
		self::assertSame( array( true, false, false ), array_column( $repair, 'primary' ) );
		self::assertSame( array( false, false, true ), array_column( $repair, 'destructive' ) );

		unset( $all['reconfigure'] );
		$in_sync = $method->invoke( $this->display(), $all, 'gh', 'GitHub', '1', true, array() );
		self::assertSame( 'Repair', $in_sync[0]['label'] );
		self::assertSame( array( true, false, false ), $off( $in_sync ) );

		self::assertSame( 'Create the webhook on GitHub.', $none[0]['description'] );
		self::assertSame( 'Make the webhook on GitHub match this site\'s settings.', $repair[0]['description'] );
		self::assertSame( 'Compare the webhook on GitHub with this site\'s settings, then ask GitHub to send a ping. Delivery counts only when it arrives.', $repair[1]['description'] );
		self::assertSame( 'Delete the webhook from GitHub and clear its local record.', $repair[2]['description'] );
		self::assertSame( 'Remove this webhook from the repository? Booster will delete the webhook on GitHub and clear its local record.', $repair[2]['confirm'] );
		self::assertSame( array( null, null ), array( $repair[0]['confirm'], $repair[1]['confirm'] ) );
		self::assertSame( array( 'Repairing…', 'Validating…', 'Removing…' ), array_column( $repair, 'busy_label' ) );
		self::assertSame( 'Setting up…', $none[0]['busy_label'] );
		self::assertSame( 'Comparing the webhook with this site\'s settings, then asking GitHub to send a ping. This can take a few seconds.', $repair[1]['busy_message'] );
	}

	public function test_validate_is_available_only_when_both_check_and_test_are_and_reports_the_unavailable_reason(): void {
		$method  = new ReflectionMethod( WebhookDisplayModel::class, 'operation_models' );
		$reasons = array(
			'check' => 'Check reason.',
			'test'  => 'Test reason.',
		);
		$slot    = static fn ( array $available ): array => $method->invoke( ( new ReflectionClass( WebhookDisplayModel::class ) )->newInstanceWithoutConstructor(), $available, 'gh', 'GitHub', '1', true, $reasons )[1];

		$both = $slot(
			array(
				'check' => true,
				'test'  => true,
			)
		);
		self::assertSame( 'validate', $both['key'] );
		self::assertFalse( $both['disabled'] );
		self::assertSame( '', $both['disabled_reason'] );
		$only_check = $slot( array( 'check' => true ) );
		self::assertTrue( $only_check['disabled'] );
		self::assertSame( 'Test reason.', $only_check['disabled_reason'] );
		$only_test = $slot( array( 'test' => true ) );
		self::assertTrue( $only_test['disabled'] );
		self::assertSame( 'Check reason.', $only_test['disabled_reason'] );
		self::assertTrue( $slot( array() )['disabled'] );
	}

	private function display(): WebhookDisplayModel {
		return ( new ReflectionClass( WebhookDisplayModel::class ) )->newInstanceWithoutConstructor();
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

// Native fixture operations prove the WP-CLI config-path fallback.

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\OnboardingPresenter;
use RAN\Admin\CredentialExpiryNotice;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\WebhookManagement\Installation\WordPressInstallationStore;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\WorkflowAssistanceState;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SiteKeyStore;
use RAN\Secrets\WpConfigSecretsPathWriter;
use RAN\Storage\Database;
use RAN\Storage\GitHubSelectionStore;
use RAN\Uninstall\LocalDataRemover;
use RAN\Settings\GeneralPreferenceStore;
use RAN\WordPress\CoreSelfUpdateCheck;
use RuntimeException;

require_once __DIR__ . '/../Support/WPError.php';
require_once __DIR__ . '/UninstallWordPressFunctions.php';

#[CoversClass( LocalDataRemover::class )]
final class LocalDataRemoverTest extends TestCase {

	private UninstallDatabase $database;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->database = new UninstallDatabase();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused database double.
		$GLOBALS['wpdb'] = $this->database;

		$GLOBALS['ran_booster_uninstall_cron_result']                       = 1;
		$GLOBALS['ran_booster_uninstall_cron_calls']                        = array();
		$GLOBALS['ran_booster_uninstall_multisite']                         = false;
		$GLOBALS['ran_booster_uninstall_current_blog_id']                   = 1;
		$GLOBALS['ran_booster_uninstall_main_site_id']                      = 1;
		$GLOBALS['ran_booster_uninstall_deleted_transients']                = array();
		$GLOBALS['ran_booster_uninstall_deleted_options']                   = array();
		$GLOBALS['ran_booster_uninstall_undeletable_option']                = null;
		$GLOBALS['ran_booster_uninstall_simulate_negative_cache']           = false;
		$GLOBALS['ran_booster_uninstall_negative_cache']                    = array();
		$GLOBALS['ran_booster_uninstall_plugin_basename']                   = 'renamed-booster/ran-booster.php';
		$GLOBALS['ran_booster_uninstall_cron']                              = array(
			WordPressWorkerWakeup::HOOK => true,
			'unrelated_cron_hook'       => true,
		);
		$GLOBALS['ran_booster_uninstall_transients']                        = array();
		$GLOBALS['ran_booster_uninstall_transients']['auto_updater.lock']   = 'wordpress-lock';
		$GLOBALS['ran_booster_uninstall_transients']['unrelated_transient'] = 'preserved';
		$GLOBALS['ran_booster_uninstall_options']                           = array(
			OnboardingPresenter::PRESENTATION_OPTION      => 'onboarding',
			Database::VERSION_OPTION                      => '5.0',
			GitHubSelectionStore::OPTION                  => $this->selection_json(),
			\RAN\Activity\ActivityStore::VERSION_OPTION   => '1',
			CredentialExpiryObservationStore::OPTION_NAME => array( 'profiles' => array() ),
			PublicRepositoryLookupProfileStore::OPTION_NAME => array( 'profiles' => array() ),
			'ran_booster_release_deployments_assessment_observations' => array( array( 'kind' => 'existing_automation_detected' ) ),
			'ran_booster_release_deployments_failure_history' => array( array( 'correlation_reference' => str_repeat( 'a', 32 ) ) ),
			$this->updater_authority_option()             => 'owned-updater-state',
			SiteKeyStore::OPTION_NAME                     => 'encoded-key',
			WordPressInstallationStore::OPTION_NAME       => array( 'current-webhook-record' ),
			GeneralPreferenceStore::OPTION_NAME           => array( 'hide_github' => true ),
			CoreSelfUpdateCheck::OPTION_NAME              => array( 'claim' => str_repeat( 'a', 32 ) ),
			'unrelated_option'                            => 'preserved',
		);
		foreach (
			array(
				WorkflowAssistanceState::SETUP_OPTION,
			) as $provider_option
		) {
			$GLOBALS['ran_booster_uninstall_options'][ $provider_option ] = array( 'current-provider-state' );
		}
		$GLOBALS['ran_booster_uninstall_options']['ran_booster_release_deployments_setup_records'] = array( 'obsolete-provider-state' );
		$this->database->tables    = array(
			'wp_ran_booster_packages',
			'wp_ran_booster_deployment_attempts',
			'wp_ran_booster_activity',
			'wp_ran_booster_rejected_admission_audit',
			'wp_ran_booster_native_update_activity',
			'wp_unrelated',
		);
		$this->database->user_meta = array(
			DevelopmentSafetyNoticeController::USER_META_KEY => array( 1 ),
			CredentialExpiryNotice::USER_META_KEY   => array( 2 ),
			DeploymentAdminPresenter::USER_META_KEY => array( 3 ),
			\RAN\Admin\Feedback\FeedbackFlash::USER_META_KEY => array( 5 ),
			'unrelated_meta'                        => array( 4 ),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_remove_deletes_the_exact_core_inventory_and_can_be_repeated(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite'] = true;

		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( null );
		$secrets->expects( self::exactly( 2 ) )
			->method( 'delete_managed_storage' )
			->willReturnCallback(
				static function (): void {
					unset( $GLOBALS['ran_booster_uninstall_options'][ SiteKeyStore::OPTION_NAME ] );
				}
			);
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );
		$remover = $this->remover( $secrets, $writer );

		$remover->remove();
		self::assertArrayNotHasKey( OnboardingPresenter::PRESENTATION_OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		$remover->remove();

		self::assertSame(
			array(
				'wp_ran_booster_rejected_admission_audit',
				'wp_ran_booster_native_update_activity',
				'wp_unrelated',
			),
			$this->database->tables
		);
		self::assertSame( array( 'unrelated_meta' => array( 4 ) ), $this->database->user_meta );
		self::assertSame(
			array(
				'ran_booster_release_deployments_assessment_observations' => array(
					array( 'kind' => 'existing_automation_detected' ),
				),
				'ran_booster_release_deployments_failure_history' => array(
					array( 'correlation_reference' => str_repeat( 'a', 32 ) ),
				),
				'unrelated_option' => 'preserved',
				'ran_booster_release_deployments_setup_records' => array( 'obsolete-provider-state' ),
			),
			$GLOBALS['ran_booster_uninstall_options']
		);
		self::assertSame(
			array(
				'auto_updater.lock'   => 'wordpress-lock',
				'unrelated_transient' => 'preserved',
			),
			$GLOBALS['ran_booster_uninstall_transients']
		);
		self::assertSame( array(), $GLOBALS['ran_booster_uninstall_deleted_transients'] );
		self::assertSame( array( 'unrelated_cron_hook' => true ), $GLOBALS['ran_booster_uninstall_cron'] );
		self::assertSame(
			array(
				WordPressWorkerWakeup::HOOK,
				WordPressWorkerWakeup::HOOK,
			),
			$GLOBALS['ran_booster_uninstall_cron_calls']
		);
	}


	/** @return iterable<string, array{string, ?string, mixed, mixed}> */
	public static function unfinished_attempts(): iterable {
		yield 'GitHub queued' => array( 'gh', 'queued', null, null );
		yield 'Bitbucket queued' => array( 'bb', 'queued', null, null );
		yield 'GitHub running' => array( 'gh', 'running', null, null );
		yield 'Bitbucket running' => array( 'bb', 'running', null, null );
		yield 'attention unresolved' => array( 'bb', 'needs_attention', null, null );
		yield 'attention missing actor' => array( 'gh', 'needs_attention', '2026-01-01 00:00:00', null );
		yield 'attention missing time' => array( 'bb', 'needs_attention', null, 7 );
		yield 'unknown state' => array( 'bb', 'pending', null, null );
		yield 'missing state' => array( 'gh', null, null, null );
		yield 'noncanonical state' => array( 'bb', 'SUCCEEDED', null, null );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'unfinished_attempts' )]
	public function test_unfinished_attempt_refuses_before_any_destructive_step( string $provider, ?string $state, mixed $resolved_at, mixed $resolved_by ): void {
		$this->setUp();
		$this->database->attempts = array(
			array(
				'provider'    => $provider,
				'state'       => $state,
				'resolved_at' => $resolved_at,
				'resolved_by' => $resolved_by,
			),
		);
		$this->assert_preflight_refuses_without_mutation();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_terminal_and_resolved_attempts_do_not_block_cleanup(): void {
		$this->setUp();
		$this->database->attempts = array(
			array(
				'provider'    => 'gh',
				'state'       => 'succeeded',
				'resolved_at' => null,
				'resolved_by' => null,
			),
			array(
				'provider'    => 'bb',
				'state'       => 'failed',
				'resolved_at' => null,
				'resolved_by' => null,
			),
			array(
				'provider'    => 'bb',
				'state'       => 'needs_attention',
				'resolved_at' => '2026-01-01 00:00:00',
				'resolved_by' => 7,
			),
		);
		$this->remover( $this->secrets( null ), $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
		self::assertArrayNotHasKey( GitHubSelectionStore::OPTION, $GLOBALS['ran_booster_uninstall_options'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_native_marker_and_malformed_selection_refuse_before_cleanup(): void {
		foreach ( array( $this->selection_json( true ), '{malformed' ) as $selection ) {
			$this->setUp();
			$GLOBALS['ran_booster_uninstall_options'][ GitHubSelectionStore::OPTION ] = $selection;
			$this->assert_preflight_refuses_without_mutation();
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_read_errors_and_unexpected_missing_journal_refuse_before_cleanup(): void {
		foreach ( array( 'SELECT option_name FROM', 'SELECT option_name, option_value, autoload', 'information_schema.TABLES', 'SELECT id FROM' ) as $failure ) {
			$this->setUp();
			$this->database->read_failure_contains = $failure;
			$this->assert_preflight_refuses_without_mutation();
		}
		foreach ( array( 'version', 'selection' ) as $remaining ) {
			$this->setUp();
			$this->database->tables = array_values( array_diff( $this->database->tables, array( 'wp_ran_booster_deployment_attempts' ) ) );
			unset( $GLOBALS['ran_booster_uninstall_options'][ 'version' === $remaining ? GitHubSelectionStore::OPTION : Database::VERSION_OPTION ] );
			$this->assert_preflight_refuses_without_mutation();
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_absent_journal_and_authorities_allow_repeat_cleanup(): void {
		$this->setUp();
		unset( $GLOBALS['ran_booster_uninstall_options'][ GitHubSelectionStore::OPTION ], $GLOBALS['ran_booster_uninstall_options'][ Database::VERSION_OPTION ] );
		$this->database->tables = array_values( array_diff( $this->database->tables, array( 'wp_ran_booster_deployment_attempts' ) ) );
		$remover                = $this->remover( $this->secrets( null ), $this->createStub( WpConfigSecretsPathWriter::class ) );
		$remover->remove();
		$remover->remove();
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	/** @return iterable<string, array{string}> */
	public static function late_option_delete_targets(): iterable {
		yield 'selection' => array( 'selection' );
		yield 'updater' => array( 'updater' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'late_option_delete_targets' )]
	public function test_failed_option_deletes_cannot_be_hidden_by_wordpress_negative_cache( string $target ): void {
		$this->setUp();
		$option = 'selection' === $target ? GitHubSelectionStore::OPTION : $this->updater_authority_option();
		$GLOBALS['ran_booster_uninstall_undeletable_option']      = $option;
		$GLOBALS['ran_booster_uninstall_simulate_negative_cache'] = true;
		$caught = null;
		try {
			$this->remover( $this->secrets( null ), $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
		} catch ( RuntimeException $failure ) {
			$caught = $failure;
		}
		self::assertInstanceOf( RuntimeException::class, $caught );
		self::assertSame( 'selection' === $target ? 'Booster options could not be removed.' : 'Booster updater state could not be removed.', $caught->getMessage() );
		self::assertArrayHasKey( $option, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'late_option_delete_targets' )]
	public function test_authoritative_option_read_error_stops_cleanup_and_allows_retry( string $target ): void {
		$this->setUp();
		$option                                = 'selection' === $target ? GitHubSelectionStore::OPTION : $this->updater_authority_option();
		$this->database->read_failure_contains = "BINARY '" . $option . "' LIMIT 1";
		$remover                               = $this->remover( $this->secrets( null ), $this->createStub( WpConfigSecretsPathWriter::class ) );
		$caught                                = null;
		try {
			$remover->remove();
		} catch ( RuntimeException $failure ) {
			$caught = $failure;
		}
		self::assertInstanceOf( RuntimeException::class, $caught );
		self::assertSame( 'selection' === $target ? 'Booster options could not be removed.' : 'Booster updater state could not be removed.', $caught->getMessage() );
		self::assertArrayNotHasKey( $option, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );
		$this->database->read_failure_contains = null;
		$remover->remove();
		self::assertNotContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_undeletable_selection_fails_after_earlier_cleanup_without_losing_unrelated_options(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_undeletable_option'] = GitHubSelectionStore::OPTION;
		$remover = $this->remover( $this->secrets( null ), $this->createStub( WpConfigSecretsPathWriter::class ) );
		try {
			$remover->remove();
			self::fail( 'The selection must be verifiably removed.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Booster options could not be removed.', $failure->getMessage() );
		}
		self::assertArrayHasKey( GitHubSelectionStore::OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );
		$GLOBALS['ran_booster_uninstall_undeletable_option'] = null;
		$remover->remove();
		self::assertNotContains( 'wp_ran_booster_deployment_attempts', $this->database->tables );
		self::assertArrayNotHasKey( GitHubSelectionStore::OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	private function assert_preflight_refuses_without_mutation(): void {
		$options = $GLOBALS['ran_booster_uninstall_options'];
		$tables  = $this->database->tables;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( null );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );
		try {
			$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
			self::fail( 'Unverifiable or unfinished custody must refuse cleanup.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'before cleanup', $failure->getMessage() );
		}
		self::assertSame( $options, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertSame( $tables, $this->database->tables );
		self::assertSame( array(), $GLOBALS['ran_booster_uninstall_deleted_options'] );
		self::assertSame( array(), $GLOBALS['ran_booster_uninstall_cron_calls'] );
	}

	private function selection_json( bool $native = false ): string {
		$declaration = new \RAN\RepositoryProvider\GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'ran-booster/ran-booster.php',
				'implementation_id'      => 'fixture',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array(),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
		$manifest    = get_object_vars( $declaration );
		unset( $manifest['implementation_fingerprint'] );
		$state = array(
			'schema'               => 1,
			'selection_generation' => 0,
			'selected'             => 'bundled',
			'declaration'          => $manifest,
			'approval'             => 'approved',
			'approved_api_formats' => array(),
		);
		if ( $native ) {
			$state['native_mutation'] = array(
				'selection_generation' => 0,
				'owner_tag'            => str_repeat( 'a', 32 ),
				'target_type'          => 'plugin',
				'installed_identity'   => 'fixture/fixture.php',
				'phase'                => 'started',
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The selection store requires canonical throwing JSON encoding.
		return json_encode( $state, JSON_THROW_ON_ERROR );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_provider_cleanup_failure_stops_before_core_database_cleanup(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_undeletable_option'] = WorkflowAssistanceState::SETUP_OPTION;

		try {
			$this->remover(
				$this->secrets( null ),
				$this->createStub( WpConfigSecretsPathWriter::class )
			)->remove();
			self::fail( 'An unverifiable bundled-provider cleanup must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Bundled GitHub provider state could not be removed.', $failure->getMessage() );
		}

		self::assertArrayHasKey( Database::VERSION_OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayHasKey( WordPressInstallationStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayHasKey( DevelopmentSafetyNoticeController::USER_META_KEY, $this->database->user_meta );
		self::assertContains( 'wp_ran_booster_packages', $this->database->tables );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_undeletable_core_update_check_option_fails_closed_after_general_preference_removal(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_undeletable_option'] = CoreSelfUpdateCheck::OPTION_NAME;

		try {
			$this->remover( $this->secrets( null ), $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
			self::fail( 'An undeletable Core update-check option must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Booster options could not be removed.', $failure->getMessage() );
		}

		self::assertArrayNotHasKey( GeneralPreferenceStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayHasKey( CoreSelfUpdateCheck::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_converted_uninstall_stops_before_secrets_or_database_on_a_non_main_site(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite']       = true;
		$GLOBALS['ran_booster_uninstall_current_blog_id'] = 2;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'path' );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not verify the converted installation cleanup scope' );
		$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_converted_uninstall_stops_before_secrets_when_the_options_table_is_not_the_base_table(): void {
		$this->setUp();
		$GLOBALS['ran_booster_uninstall_multisite'] = true;
		$this->database->options                    = 'wp_2_options';
		$secrets                                    = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'path' );
		$secrets->expects( self::never() )->method( 'assert_managed_storage_deletable' );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not verify the converted installation cleanup scope' );
		$this->remover( $secrets, $this->createStub( WpConfigSecretsPathWriter::class ) )->remove();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsafe_secrets_abort_before_database_cleanup_and_preserve_the_key(): void {
		$this->setUp();
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( '/private/secrets.json' );
		$secrets->method( 'assert_managed_storage_deletable' )
			->willThrowException( new RuntimeException( 'sensitive path must not escape' ) );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );

		$remover = $this->remover(
			$secrets,
			$writer,
			'/site/wp-config.php'
		);

		try {
			$remover->remove();
			self::fail( 'Unsafe managed secrets must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'sensitive path must not escape', $failure->getMessage() );
		}

		self::assertArrayHasKey( SiteKeyStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertArrayHasKey( WordPressWorkerWakeup::HOOK, $GLOBALS['ran_booster_uninstall_cron'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ambiguous_config_ownership_aborts_before_any_managed_storage_or_database_cleanup(): void {
		$this->setUp();
		$sidecar = '/private/secrets.json';
		$config  = '/site/wp-config.php';
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( $sidecar );
		$secrets->expects( self::never() )->method( 'assert_managed_storage_deletable' );
		$secrets->expects( self::never() )->method( 'delete_managed_storage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::once() )
			->method( 'assert_owned_definition_removable' )
			->with( $config, $sidecar )
			->willThrowException( new RuntimeException( 'owned definition is ambiguous' ) );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );

		try {
			$this->remover( $secrets, $writer, $config )->remove();
			self::fail( 'Ambiguous configuration ownership must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'ambiguous', $failure->getMessage() );
		}

		self::assertArrayHasKey( SiteKeyStore::OPTION_NAME, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertArrayHasKey( WordPressWorkerWakeup::HOOK, $GLOBALS['ran_booster_uninstall_cron'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'failed_drop_tables' )]
	public function test_database_failure_leaves_a_repeatable_partial_cleanup( string $failed_table ): void {
		$this->setUp();
		$this->database->failure_contains = $failed_table;
		$secrets                          = $this->secrets( null );
		$secrets->method( 'delete_managed_storage' )
			->willReturnCallback(
				static function (): void {
					unset( $GLOBALS['ran_booster_uninstall_options'][ SiteKeyStore::OPTION_NAME ] );
				}
			);
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::never() )->method( 'remove_owned_definition' );
		$remover = $this->remover( $secrets, $writer );

		try {
			$remover->remove();
			self::fail( 'A database cleanup failure must abort uninstall.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Booster tables could not be removed.', $failure->getMessage() );
		}

		self::assertNotContains( 'wp_ran_booster_packages', $this->database->tables );
		self::assertContains( $failed_table, $this->database->tables );
		self::assertSame( 'wp_ran_booster_deployment_attempts' === $failed_table, in_array( 'wp_ran_booster_deployment_attempts', $this->database->tables, true ) );
		self::assertArrayNotHasKey( Database::VERSION_OPTION, $GLOBALS['ran_booster_uninstall_options'] );
		self::assertArrayNotHasKey( GitHubSelectionStore::OPTION, $GLOBALS['ran_booster_uninstall_options'] );

		$this->database->failure_contains = null;
		$remover->remove();
		self::assertSame(
			array(
				'wp_ran_booster_rejected_admission_audit',
				'wp_ran_booster_native_update_activity',
				'wp_unrelated',
			),
			$this->database->tables
		);
		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	/** @return list<array{string}> */
	public static function failed_drop_tables(): array {
		return array( array( 'wp_ran_booster_deployment_attempts' ), array( 'wp_ran_booster_activity' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_configured_path_is_passed_only_to_the_narrow_config_inverse(): void {
		$this->setUp();
		$sidecar = '/private/secrets.json';
		$config  = '/site/wp-config.php';
		$secrets = $this->secrets( $sidecar );
		$secrets->method( 'delete_managed_storage' );
		$writer = $this->createMock( WpConfigSecretsPathWriter::class );
		$writer->expects( self::once() )
			->method( 'remove_owned_definition' )
			->with( $config, $sidecar )
			->willReturn( false );

		$remover = new TestableLocalDataRemover(
			$secrets,
			new TemporaryDebugCapture( null ),
			$writer,
			$this->database,
			$config
		);
		$remover->remove();

		self::assertSame( 'preserved', $GLOBALS['ran_booster_uninstall_options']['unrelated_option'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wp_cli_uses_the_only_canonical_supported_config_when_includes_are_hidden(): void {
		$this->setUp();
		$root   = (string) realpath( sys_get_temp_dir() )
			. '/ran-booster-uninstall-config-'
			. bin2hex( random_bytes( 6 ) );
		$config = $root . '/wp-config.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $root, 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $config, "<?php\n" ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'ABSPATH', $root . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
		define( 'WP_CLI', true );

		$remover = new class(
			$this->secrets( null ),
			new TemporaryDebugCapture( null ),
			$this->createStub( WpConfigSecretsPathWriter::class ),
			$this->database
		) extends LocalDataRemover {
			public function __construct(
				SecretsFile $secrets,
				TemporaryDebugCapture $capture,
				WpConfigSecretsPathWriter $writer,
				object $database
			) {
				parent::__construct( $secrets, $capture, $writer, database: $database );
			}

			public function discovered_config_path(): string {
				return $this->loaded_wp_config_path();
			}
		};

		try {
			self::assertSame( $config, $remover->discovered_config_path() );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
			unlink( $config );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
			rmdir( $root );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_remove_deletes_the_exact_config_lock_and_empty_automatic_directories(): void {
		$this->setUp();
		$root        = (string) realpath( sys_get_temp_dir() )
			. '/ran-booster-uninstall-directories-'
			. bin2hex( random_bytes( 6 ) );
		$base        = $root . '/.ran-booster';
		$site        = $base . '/site-fingerprint';
		$sidecar     = $site . '/secrets.json';
		$config      = $root . '/wp-config.php';
		$config_lock = $config . '.ran-booster.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $site, 0700, true ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $config, "<?php\n" ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact disposable fixture bytes to exercise native filesystem boundaries.
		self::assertNotFalse( file_put_contents( $config_lock, '' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $base, 0700 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $config_lock, 0600 ) );

		$secrets = $this->secrets( $sidecar );
		$remover = new class(
			$secrets,
			new TemporaryDebugCapture( $sidecar ),
			$this->createStub( WpConfigSecretsPathWriter::class ),
			$this->database,
			$config,
			$sidecar
		) extends LocalDataRemover {
			public function __construct(
				SecretsFile $secrets,
				TemporaryDebugCapture $capture,
				WpConfigSecretsPathWriter $writer,
				object $database,
				private readonly string $config_path,
				private readonly string $automatic_path
			) {
				parent::__construct( $secrets, $capture, $writer, database: $database );
			}

			protected function loaded_wp_config_path(): string {
				return $this->config_path;
			}

			protected function automatic_sidecar_path(): string {
				return $this->automatic_path;
			}
		};

		try {
			$remover->remove();
			$remover->remove();
			self::assertFileDoesNotExist( $config_lock );
			self::assertDirectoryDoesNotExist( $site );
			self::assertDirectoryDoesNotExist( $base );
		} finally {
			if ( is_file( $config_lock ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
				unlink( $config_lock );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
			unlink( $config );
			if ( is_dir( $site ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
				rmdir( $site );
			}
			if ( is_dir( $base ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
				rmdir( $base );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
			rmdir( $root );
		}
	}

	/** @return array<string, array{string}> */
	public static function unsafe_directory_cases(): array {
		return array(
			'empty site fails before mutations'       => array( 'preflight' ),
			'site becomes empty during cleanup'       => array( 'site' ),
			'parent becomes empty after site removal' => array( 'base' ),
			'safe site loses private permissions'     => array( 'changed' ),
		);
	}

	#[DataProvider( 'unsafe_directory_cases' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsafe_empty_directories_stop_at_their_current_cleanup_boundary( string $phase ): void {
		$this->setUp();
		$root    = (string) realpath( sys_get_temp_dir() ) . '/ran-booster-uninstall-safety-' . bin2hex( random_bytes( 6 ) );
		$base    = $root . '/.ran-booster';
		$site    = $base . '/site-fingerprint';
		$marker  = $site . '/retained-until-cleanup';
		$sidecar = $site . '/secrets.json';
		$unsafe  = 'base' === $phase ? $base : $site;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only this randomized disposable native filesystem fixture.
		self::assertTrue( mkdir( $site, 0700, true ) );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Exercise rejection of an empty directory that is not private.
			self::assertTrue( chmod( $unsafe, 'changed' === $phase ? 0700 : 0755 ) );
			if ( 'site' === $phase ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Make the disposable directory ineligible until the existing secrets-deletion phase.
				self::assertNotFalse( file_put_contents( $marker, '' ) );
			}
			clearstatcache();
			$secrets = $this->createMock( SecretsFile::class );
			$secrets->method( 'path' )->willReturn( $sidecar );
			$secrets->expects( 'preflight' === $phase ? self::never() : self::once() )
				->method( 'delete_managed_storage' )
				->willReturnCallback(
					static function () use ( $marker, $site, $phase ): void {
						if ( is_file( $marker ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Model managed-file deletion using only this fixture-owned marker.
							self::assertTrue( unlink( $marker ) );
						}
						if ( 'changed' === $phase ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Change fixture permissions after preflight to prove removal rechecks current metadata.
							self::assertTrue( chmod( $site, 0755 ) );
						}
						clearstatcache();
					}
				);
			$remover = new TestableLocalDataRemover(
				$secrets,
				new TemporaryDebugCapture( null ),
				$this->createStub( WpConfigSecretsPathWriter::class ),
				$this->database,
				$root . '/wp-config.php',
				$sidecar
			);

			try {
				$remover->remove();
				self::fail( 'Unsafe empty automatic storage must stop cleanup.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'An empty Booster storage directory could not be removed safely.', $failure->getMessage() );
			}

			self::assertDirectoryExists( $base );
			self::assertSame( 'base' !== $phase, is_dir( $site ) );
			self::assertSame( 'preflight' === $phase, in_array( 'wp_ran_booster_packages', $this->database->tables, true ) );
			self::assertSame( 'preflight' === $phase, isset( $GLOBALS['ran_booster_uninstall_cron'][ WordPressWorkerWakeup::HOOK ] ) );
		} finally {
			if ( is_file( $marker ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the fixture-owned marker after failure.
				unlink( $marker );
			}
			foreach ( array( $site, $base, $root ) as $directory ) {
				if ( is_dir( $directory ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable directories created by this test.
					rmdir( $directory );
				}
			}
		}
	}

	/** @return SecretsFile&\PHPUnit\Framework\MockObject\Stub */
	private function secrets( ?string $path ): SecretsFile {
		$secrets = $this->createStub( SecretsFile::class );
		$secrets->method( 'path' )->willReturn( $path );

		return $secrets;
	}

	private function remover(
		SecretsFile $secrets,
		WpConfigSecretsPathWriter $writer,
		?string $config_path = null
	): LocalDataRemover {
		return new TestableLocalDataRemover(
			$secrets,
			new TemporaryDebugCapture( null ),
			$writer,
			$this->database,
			$config_path
		);
	}

	private function updater_authority_option(): string {
		$target = implode( "\0", array( 'plugin', 'ran-booster', 'ran-booster.php' ) );
		return 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $target ), 0, 32 );
	}
}

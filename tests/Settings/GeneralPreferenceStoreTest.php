<?php

declare(strict_types=1);

namespace RAN\Tests\Settings;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RAN\Activity\ActivityStore;
use RAN\PackageArtifactLimit;
use RAN\Settings\GeneralPreferenceStore;
use RAN\WordPress\CoreSelfUpdatePolicy;

require_once __DIR__ . '/GeneralPreferenceStoreWordPressFunctions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GeneralPreferenceStoreTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$GLOBALS['ran_booster_general_preference_raw']   = null;
		$GLOBALS['ran_booster_general_preference_cache'] = array();
		$GLOBALS['ran_booster_general_preference_reads'] = 0;
		unset( $GLOBALS['ran_booster_general_preference_swap_after_first_read'] );
		unset( $GLOBALS['ran_booster_general_preference_cached_option'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Controlled test-only database boundary.
		$GLOBALS['wpdb'] = new GeneralPreferenceDatabase();
	}

	public function test_saved_github_hide_fallback_applies_without_a_code_override_and_rejects_malformed_saved_values(): void {
		$this->seed(
			array(
				'hide_github'  => false,
				'history_rows' => '300',
				'unknown'      => 'retained',
			)
		);
		$projection = ( new GeneralPreferenceStore() )->projection();

		self::assertSame( 'saved', $projection['hide_github']['source'] );
		self::assertFalse( $projection['hide_github']['value'] );
		self::assertSame( 2000, $projection['history_rows']['value'] );
		self::assertSame( 'invalid_saved', $projection['history_rows']['error'] );
	}

	public function test_old_archive_preference_is_inert_after_the_beta_rename(): void {
		$this->seed( array( 'max_archive_bytes' => 1048576 ) );
		$store = new GeneralPreferenceStore();
		self::assertSame( 52428800, $store->projection()['max_download_bytes']['value'] );
		self::assertSame( 'default', $store->projection()['max_download_bytes']['source'] );
		self::assertSame( 52428800, PackageArtifactLimit::resolve() );
	}

	public function test_malformed_option_document_is_visible_and_cannot_be_saved(): void {
		$GLOBALS['ran_booster_general_preference_raw'] = 'not-an-option-document';
		$store = new GeneralPreferenceStore();

		self::assertTrue( $store->snapshot()['malformed'] );
		self::assertSame( 'invalid_saved', $store->projection()['hide_github']['error'] );
		self::assertSame( 'failed', $store->save( $store->snapshot()['token'], array(), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
	}

	public function test_view_snapshot_never_pairs_fields_with_a_token_from_a_later_read(): void {
		$this->seed( array( 'history_rows' => 300 ) );
		$GLOBALS['ran_booster_general_preference_cached_option'] = array( 'history_rows' => 400 );

		$view = ( new GeneralPreferenceStore() )->view_snapshot();

		self::assertSame( 1, $GLOBALS['wpdb']->fresh_reads );
		self::assertSame( 300, $view['fields']['history_rows']['value'] );
		self::assertSame( hash( 'sha256', \RAN\Settings\maybe_serialize( array( 'history_rows' => 300 ) ) ), $view['token'] );
	}

	public function test_self_update_policy_refreshes_preferences_only_for_explicit_admission(): void {
		$this->seed( array( 'self_update_discovery' => true ) );
		$first = CoreSelfUpdatePolicy::detect( '/missing/ran-booster.php', '1.2.3' );
		self::assertSame( 'auto', $first->diagnostics()['requested_mode'] );
		self::assertSame( array(), $GLOBALS['ran_booster_general_preference_cache'], 'Ordinary bootstrap policy reads preserve the option cache.' );
		self::assertSame( 0, $GLOBALS['wpdb']->fresh_reads, 'Ordinary bootstrap policy reads preserve the cached option path.' );

		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize( array( 'self_update_discovery' => false ) );
		self::assertTrue( ( new GeneralPreferenceStore() )->projection()['self_update_discovery']['value'] );

		$second = CoreSelfUpdatePolicy::detect( '/missing/ran-booster.php', '1.2.3', true );
		self::assertSame( 'disabled', $second->diagnostics()['requested_mode'] );
		self::assertSame( 1, $GLOBALS['wpdb']->fresh_reads, 'Explicit admission reads the persisted option directly.' );
		self::assertSame( array(), $GLOBALS['ran_booster_general_preference_cache'], 'Fresh admission does not evict alloptions.' );
	}

	public function test_save_uses_the_posted_snapshot_preserves_unknown_values_and_rejects_stale_hide(): void {
		$this->seed( array( 'unknown' => 'retained' ) );
		$store  = new GeneralPreferenceStore();
		$token  = $store->snapshot()['token'];
		$result = $store->save(
			$token,
			array(
				'history_days'          => '7',
				'hide_github'           => '1',
				'history_rows'          => '300',
				'max_download_bytes'    => '1048576',
				'self_update_discovery' => '0',
			),
			static fn ( callable $operation ): string => $operation(),
			static fn (): bool => true
		);

		self::assertSame( 'saved', $result );
		self::assertSame(
			array(
				'unknown'               => 'retained',
				'hide_github'           => true,
				'self_update_discovery' => false,
				'history_rows'          => 300,
				'history_days'          => 7,
				'max_download_bytes'    => 1048576,
				'_revisions'            => array(
					'hide_github'           => 1,
					'self_update_discovery' => 1,
					'numeric'               => 1,
				),
			),
			\RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )
		);
		self::assertSame( 'stale', $store->save( 'not-the-token', array(), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
		self::assertSame( 'invalid', $store->save( $store->snapshot()['token'], array( 'hide_github' => '1.0' ), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
	}

	public function test_confirmed_management_clears_true_but_never_seeds_an_absent_value(): void {
		$store = new GeneralPreferenceStore();
		self::assertTrue( $store->clear_confirmed_github_hide_request() );
		self::assertNull( $GLOBALS['ran_booster_general_preference_raw'] );
		$this->seed(
			array(
				'hide_github'  => true,
				'history_rows' => 300,
			)
		);
		self::assertTrue( $store->clear_confirmed_github_hide_request() );
		self::assertSame( false, \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['hide_github'] );
	}

	public function test_parser_rejects_unknown_arrays_exponents_overflow_and_out_of_range_values(): void {
		$store  = new GeneralPreferenceStore();
		$values = $this->submission();
		foreach (
			array(
				array( 'unknown' => '1' ),
				array( 'history_rows' => array( '300' ) ),
				array( 'history_rows' => '3e2' ),
				array( 'history_rows' => '999999999999999999999999' ),
				array( 'history_days' => '0' ),
				array( 'max_download_bytes' => '536870912' ),
			) as $change
		) {
			$input = array_merge( $values, $change );
			self::assertNull( $store->parse_submission( $input, array_keys( $values ) ) );
		}
		$values['history_rows']       = '200';
		$values['history_days']       = '365';
		$values['max_download_bytes'] = '536870911';
		self::assertSame(
			array(
				'hide_github'           => false,
				'self_update_discovery' => true,
				'history_rows'          => 200,
				'history_days'          => 365,
				'max_download_bytes'    => 536870911,
			),
			$store->parse_submission( $values, array_keys( $values ) )
		);
	}

	public function test_archive_mib_submission_rounds_new_values_and_preserves_existing_exact_bytes(): void {
		$store  = new GeneralPreferenceStore();
		$values = $this->submission();
		unset( $values['max_download_bytes'] );

		$values['max_download_mib'] = '50.5';
		$parsed                     = $store->parse_submission( $values, array_keys( $this->submission() ) );
		self::assertIsArray( $parsed );
		self::assertSame( 52953088, $parsed['max_download_bytes'] );
		$values['max_download_mib'] = '50.1';
		$parsed                     = $store->parse_submission( $values, array_keys( $this->submission() ) );
		self::assertIsArray( $parsed );
		self::assertSame( 52533658, $parsed['max_download_bytes'] );

		$values['max_download_mib'] = '50.00000095367431640625';
		$parsed                     = $store->parse_submission( $values, array_keys( $this->submission() ) );
		self::assertIsArray( $parsed );
		self::assertSame( 52428801, $parsed['max_download_bytes'], 'An existing exact byte limit must round trip through its finite MiB representation.' );
		$this->seed( array( 'max_download_bytes' => 52428801 ) );
		self::assertSame(
			'saved',
			$store->save( $store->snapshot()['token'], $values, static fn ( callable $operation ): string => $operation(), static fn (): bool => true )
		);
		self::assertSame( 52428801, \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['max_download_bytes'] );

		foreach ( array( '0', '-1', '512', '5e1', '999999999999999999999999' ) as $mib ) {
			$values['max_download_mib'] = $mib;
			self::assertNull( $store->parse_submission( $values, array_keys( $this->submission() ) ), $mib );
		}
	}

	public function test_unchanged_and_failed_writes_are_reported_honestly(): void {
		$store = new GeneralPreferenceStore();
		$token = $store->snapshot()['token'];
		self::assertSame( 'saved', $store->save( $token, $this->submission(), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
		$GLOBALS['wpdb']->fail_writes = true;
		self::assertSame( 'failed', $store->save( $store->snapshot()['token'], array_merge( $this->submission(), array( 'history_rows' => '300' ) ), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
	}

	public function test_ineligible_save_preserves_dormant_hide_and_rejects_a_stale_hide_field(): void {
		$this->seed( array( 'hide_github' => true ) );
		$store        = new GeneralPreferenceStore();
		$token        = $store->snapshot()['token'];
		$without_hide = $this->submission();
		unset( $without_hide['hide_github'] );

		self::assertSame( 'saved', $store->save( $token, array_merge( $without_hide, array( 'history_rows' => '300' ) ), static fn ( callable $operation ): string => $operation(), static fn (): bool => false ) );
		self::assertTrue( \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['hide_github'] );
		self::assertSame( 'stale', $store->save( $store->snapshot()['token'], $this->submission(), static fn ( callable $operation ): string => $operation(), static fn (): bool => false ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_true_github_hide_override_locks_the_code_value(): void {
		define( 'RAN_BOOSTER_HIDE_GITHUB_UI', true );
		$this->seed( array( 'hide_github' => false ) );

		$field = ( new GeneralPreferenceStore() )->projection()['hide_github'];

		self::assertSame(
			array(
				'value'  => true,
				'source' => 'code',
				'locked' => true,
				'error'  => null,
			),
			$field
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_false_github_hide_override_preserves_the_dormant_saved_value_on_save(): void {
		define( 'RAN_BOOSTER_HIDE_GITHUB_UI', false );
		$this->seed( array( 'hide_github' => true ) );
		$store  = new GeneralPreferenceStore();
		$token  = $store->snapshot()['token'];
		$values = $this->submission();
		unset( $values['hide_github'] );

		self::assertSame( 'saved', $store->save( $token, array_merge( $values, array( 'history_rows' => '300' ) ), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
		self::assertTrue( \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['hide_github'] );
		$GLOBALS['ran_booster_general_preference_cache'] = array();
		self::assertTrue( $store->clear_confirmed_github_hide_request() );
		self::assertFalse( \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['hide_github'] );
		self::assertSame( array(), $GLOBALS['ran_booster_general_preference_cache'], 'The outer management transaction owns cache invalidation after COMMIT.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_null_github_hide_override_is_locked_and_invalid(): void {
		define( 'RAN_BOOSTER_HIDE_GITHUB_UI', null );
		$this->seed( array( 'hide_github' => true ) );

		self::assertSame(
			array(
				'value'  => false,
				'source' => 'code',
				'locked' => true,
				'error'  => 'invalid_code',
			),
			( new GeneralPreferenceStore() )->projection()['hide_github']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_non_boolean_github_hide_override_is_locked_and_invalid(): void {
		define( 'RAN_BOOSTER_HIDE_GITHUB_UI', 'yes' );
		$this->seed( array( 'hide_github' => true ) );

		self::assertSame(
			array(
				'value'  => false,
				'source' => 'code',
				'locked' => true,
				'error'  => 'invalid_code',
			),
			( new GeneralPreferenceStore() )->projection()['hide_github']
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_code_locks_preserve_dormant_saved_values_and_invalid_code_is_visible(): void {
		define( 'RAN_BOOSTER_SELF_UPDATE_MODE', 'disabled' );
		define( 'RAN_BOOSTER_HISTORY_ROWS', null );
		define( 'RAN_BOOSTER_HISTORY_DAYS', 1 );
		define( 'RAN_BOOSTER_MAX_DOWNLOAD_BYTES', false );
		$this->seed(
			array(
				'self_update_discovery' => true,
				'history_rows'          => 300,
				'history_days'          => 7,
				'max_download_bytes'    => 1048576,
			)
		);
		$store      = new GeneralPreferenceStore();
		$projection = $store->projection();

		self::assertTrue( $projection['self_update_discovery']['locked'] );
		self::assertFalse( $projection['self_update_discovery']['value'] );
		self::assertSame( 'invalid_code', $projection['history_rows']['error'] );
		self::assertSame( 'invalid_code', $projection['max_download_bytes']['error'] );
		self::assertSame( 'saved', $store->save( $store->snapshot()['token'], array( 'hide_github' => '1' ), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
		$stored = \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] );
		self::assertSame( true, $stored['self_update_discovery'] );
		self::assertSame( 300, $stored['history_rows'] );
	}

	public function test_actual_policy_consumers_use_valid_saved_fallbacks(): void {
		$this->seed(
			array(
				'history_rows'          => 300,
				'history_days'          => 7,
				'max_download_bytes'    => 1048576,
				'self_update_discovery' => false,
			)
		);

		self::assertSame(
			array(
				'valid'        => true,
				'maximum_rows' => 300,
				'maximum_days' => 7,
				'source'       => 'configured',
			),
			ActivityStore::retention_policy()
		);
		self::assertSame( 1048576, PackageArtifactLimit::resolve() );
		self::assertSame( 'disabled', CoreSelfUpdatePolicy::detect( '/missing/ran-booster.php', '1.2.3' )->diagnostics()['requested_mode'] );
	}

	public function test_scoped_tokens_isolate_changes_and_reject_unchanged_retries_and_aba(): void {
		$store    = new GeneralPreferenceStore();
		$lock     = static fn ( callable $operation ): string => $operation();
		$eligible = static fn (): bool => true;
		$initial  = $store->view_snapshot()['tokens'];
		self::assertSame( 'saved', $store->save( $initial['hide_github'], array( 'hide_github' => '1' ), $lock, $eligible, 'hide_github' ) );
		$after = $store->view_snapshot()['tokens'];
		self::assertSame( $initial['numeric'], $after['numeric'] );
		self::assertSame( 'saved', $store->save( $after['hide_github'], array( 'hide_github' => '0' ), $lock, $eligible, 'hide_github' ) );
		self::assertSame( 'stale', $store->save( $initial['hide_github'], array( 'hide_github' => '1' ), $lock, $eligible, 'hide_github' ) );
		$token = $store->view_snapshot()['tokens']['hide_github'];
		self::assertSame( 'saved', $store->save( $token, array( 'hide_github' => '0' ), $lock, $eligible, 'hide_github' ) );
		self::assertSame( 'stale', $store->save( $token, array( 'hide_github' => '0' ), $lock, $eligible, 'hide_github' ) );
		self::assertSame(
			'invalid',
			$store->save(
				$store->view_snapshot()['tokens']['hide_github'],
				array(
					'hide_github'  => '1',
					'history_rows' => '300',
				),
				$lock,
				$eligible,
				'hide_github'
			)
		);
		self::assertSame( 'stale', $store->save( $store->view_snapshot()['tokens']['hide_github'], array( 'hide_github' => '1' ), $lock, static fn (): bool => false, 'hide_github' ) );
	}

	public function test_numeric_scope_saves_only_numeric_values_and_keeps_toggle_token(): void {
		$store    = new GeneralPreferenceStore();
		$lock     = static fn ( callable $operation ): string => $operation();
		$eligible = static fn (): bool => true;
		$before   = $store->view_snapshot()['tokens'];
		$values   = array(
			'history_rows'     => '300',
			'history_days'     => '7',
			'max_download_mib' => '50',
		);
		self::assertSame( 'saved', $store->save( $before['numeric'], $values, $lock, $eligible, 'numeric' ) );
		$after = $store->view_snapshot()['tokens'];
		self::assertSame( $before['hide_github'], $after['hide_github'] );
		self::assertSame( $before['self_update_discovery'], $after['self_update_discovery'] );
		self::assertSame( 300, $store->projection()['history_rows']['value'] );
	}

	public function test_locked_toggle_scope_is_rejected(): void {
		define( 'RAN_BOOSTER_SELF_UPDATE_MODE', 'disabled' );
		$store = new GeneralPreferenceStore();
		self::assertSame( 'invalid', $store->save( $store->view_snapshot()['tokens']['self_update_discovery'], array( 'self_update_discovery' => '1' ), static fn ( callable $operation ): string => $operation(), static fn (): bool => true, 'self_update_discovery' ) );
	}

	public function test_corrupt_revisions_fail_closed_and_management_clear_advances_hide(): void {
		$store    = new GeneralPreferenceStore();
		$lock     = static fn ( callable $operation ): string => $operation();
		$eligible = static fn (): bool => true;
		foreach ( array(
			null,
			array(),
			array( 'hide_github' => 0 ),
			array(
				'hide_github'           => 0,
				'self_update_discovery' => 0,
				'numeric'               => 0,
				'extra'                 => -1,
			),
			array(
				'hide_github'           => 0,
				'self_update_discovery' => 0,
				'numeric'               => 0,
				'extra'                 => '0',
			),
			array(
				'hide_github'           => 0,
				'self_update_discovery' => 0,
				'numeric'               => 0,
				7                       => 0,
			),
			array(
				'hide_github'           => -1,
				'self_update_discovery' => 0,
				'numeric'               => 0,
			),
			array(
				'hide_github'           => PHP_INT_MAX,
				'self_update_discovery' => 0,
				'numeric'               => 0,
			),
			array(
				'hide_github'           => 0,
				'self_update_discovery' => 0,
				'numeric'               => '0',
			),
		) as $bad ) {
			$this->seed(
				array(
					'hide_github' => true,
					'_revisions'  => $bad,
				)
			);
			self::assertSame( 'failed', $store->save( $store->snapshot()['token'], $this->submission(), $lock, $eligible ) );
			self::assertFalse( $store->clear_confirmed_github_hide_request() );
		}
		$this->seed(
			array(
				'_revisions' => array(
					'hide_github'           => 0,
					'self_update_discovery' => 0,
					'numeric'               => PHP_INT_MAX,
				),
			)
		);
		$token = $store->view_snapshot()['tokens']['numeric'];
		self::assertSame(
			'failed',
			$store->save(
				$token,
				array(
					'history_rows'       => '2000',
					'history_days'       => '30',
					'max_download_bytes' => '52428800',
				),
				$lock,
				$eligible,
				'numeric'
			)
		);
		$this->seed( array( 'hide_github' => true ) );
		$before = $store->view_snapshot()['tokens'];
		self::assertTrue( $store->clear_confirmed_github_hide_request() );
		$after = $store->view_snapshot()['tokens'];
		self::assertNotSame( $before['hide_github'], $after['hide_github'] );
		self::assertSame( $before['numeric'], $after['numeric'] );
		self::assertSame(
			array(
				'hide_github'           => 1,
				'self_update_discovery' => 0,
				'numeric'               => 0,
			),
			\RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['_revisions']
		);
	}

	public function test_unknown_revision_scopes_pass_through_and_round_trip_opaquely(): void {
		$store    = new GeneralPreferenceStore();
		$lock     = static fn ( callable $operation ): string => $operation();
		$eligible = static fn (): bool => true;
		$read     = static fn (): array => \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['_revisions'];
		$this->seed(
			array(
				'hide_github' => true,
				'_revisions'  => array(
					'notify_releases'       => 7,
					'hide_github'           => 3,
					'self_update_discovery' => 1,
					'numeric'               => 2,
					'zz_future'             => PHP_INT_MAX,
				),
			)
		);
		self::assertFalse( $store->snapshot()['malformed'] );
		$tokens = $store->view_snapshot()['tokens'];
		$this->seed(
			array(
				'hide_github' => true,
				'_revisions'  => array(
					'hide_github'           => 3,
					'self_update_discovery' => 1,
					'numeric'               => 2,
				),
			)
		);
		self::assertSame( $tokens, $store->view_snapshot()['tokens'], 'Known-scope tokens must not depend on unknown counters.' );
		$this->seed(
			array(
				'hide_github' => true,
				'_revisions'  => array(
					'notify_releases'       => 7,
					'hide_github'           => 3,
					'self_update_discovery' => 1,
					'numeric'               => 2,
					'zz_future'             => PHP_INT_MAX,
				),
			)
		);
		self::assertSame(
			'saved',
			$store->save(
				$store->view_snapshot()['tokens']['numeric'],
				array(
					'history_rows'       => '300',
					'history_days'       => '30',
					'max_download_bytes' => '52428800',
				),
				$lock,
				$eligible,
				'numeric'
			)
		);
		self::assertSame(
			array(
				'notify_releases'       => 7,
				'hide_github'           => 3,
				'self_update_discovery' => 1,
				'numeric'               => 3,
				'zz_future'             => PHP_INT_MAX,
			),
			$read()
		);
		self::assertSame( 'saved', $store->save( $store->snapshot()['token'], array_merge( $this->submission(), array( 'history_rows' => '400' ) ), $lock, $eligible ) );
		self::assertSame(
			array(
				'notify_releases'       => 7,
				'hide_github'           => 4,
				'self_update_discovery' => 2,
				'numeric'               => 4,
				'zz_future'             => PHP_INT_MAX,
			),
			$read()
		);
		$this->seed(
			array(
				'hide_github' => true,
				'_revisions'  => array(
					'hide_github'           => 0,
					'self_update_discovery' => 0,
					'numeric'               => 0,
					'notify_releases'       => 7,
				),
			)
		);
		self::assertTrue( $store->clear_confirmed_github_hide_request() );
		self::assertSame( 1, $read()['hide_github'] );
		self::assertSame( 7, $read()['notify_releases'] );
	}

	public function test_tokens_are_bound_to_the_fixed_scope_even_with_matching_absent_values(): void {
		$tokens = ( new GeneralPreferenceStore() )->view_snapshot()['tokens'];
		self::assertNotSame( $tokens['hide_github'], $tokens['self_update_discovery'] );
	}

	public function test_full_noop_initializes_and_advances_all_revisions(): void {
		$store    = new GeneralPreferenceStore();
		$lock     = static fn ( callable $operation ): string => $operation();
		$eligible = static fn (): bool => true;
		self::assertNull( $GLOBALS['ran_booster_general_preference_raw'] );
		$store->view_snapshot();
		self::assertNull( $GLOBALS['ran_booster_general_preference_raw'] );
		foreach ( array( 1, 2 ) as $revision ) {
			self::assertSame( 'saved', $store->save( $store->snapshot()['token'], $this->submission(), $lock, $eligible ) );
			self::assertSame( array_fill_keys( array( 'hide_github', 'self_update_discovery', 'numeric' ), $revision ), \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['_revisions'] );
		}
	}

	public function test_scope_token_tracks_raw_presence_and_type_but_not_document_order(): void {
		$store = new GeneralPreferenceStore();
		$this->seed(
			array(
				'history_rows' => 300,
				'history_days' => 7,
			)
		);
		$first = $store->view_snapshot()['tokens'];
		$this->seed(
			array(
				'history_days' => 7,
				'history_rows' => 300,
			)
		);
		self::assertSame( $first, $store->view_snapshot()['tokens'] );
		$this->seed( array() );
		$absent = $store->view_snapshot()['tokens']['hide_github'];
		$this->seed( array( 'hide_github' => false ) );
		$explicit = $store->view_snapshot()['tokens']['hide_github'];
		self::assertNotSame( $absent, $explicit );
		$this->seed( array( 'hide_github' => 0 ) );
		self::assertNotSame( $explicit, $store->view_snapshot()['tokens']['hide_github'] );
	}

	public function test_management_clear_fences_late_scoped_hide_and_old_writer_boundary(): void {
		$store    = new GeneralPreferenceStore();
		$lock     = static fn ( callable $operation ): string => $operation();
		$eligible = static fn (): bool => true;
		$this->seed( array( 'hide_github' => true ) );
		$before   = $store->view_snapshot()['tokens']['hide_github'];
		self::assertTrue( $store->clear_confirmed_github_hide_request() );
		self::assertSame( 'stale', $store->save( $before, array( 'hide_github' => '1' ), $lock, $eligible, 'hide_github' ) );
		$after = $store->view_snapshot()['tokens']['hide_github'];
		// An old writer can preserve the unknown revision map while changing and restoring the value.
		$document                = \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] );
		$document['hide_github'] = true;
		$this->seed( $document );
		$document['hide_github'] = false;
		$this->seed( $document );
		self::assertSame( $after, $store->view_snapshot()['tokens']['hide_github'], 'Older writers do not advance the counter; ABA protection does not extend across them.' );
	}

	public function test_failed_write_or_commit_does_not_advance_metadata_in_rollback_fixture(): void {
		$store                        = new GeneralPreferenceStore();
		$lock                         = static fn ( callable $operation ): string => $operation();
		$eligible                     = static fn (): bool => true;
		$this->seed( array( 'history_rows' => 300 ) );
		$original                     = $GLOBALS['ran_booster_general_preference_raw'];
		$GLOBALS['wpdb']->fail_writes = true;
		self::assertSame( 'failed', $store->save( $store->snapshot()['token'], $this->submission(), $lock, $eligible ) );
		self::assertSame( $original, $GLOBALS['ran_booster_general_preference_raw'] );
		$GLOBALS['wpdb']->fail_writes = false;
		$GLOBALS['wpdb']->fail_commit = true;
		self::assertSame( 'failed', $store->save( $store->snapshot()['token'], $this->submission(), $lock, $eligible ) );
		self::assertSame( $original, $GLOBALS['ran_booster_general_preference_raw'] );
	}

	/** @param array<string,mixed> $value */
	private function seed( array $value ): void {
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize( $value );
	}

	/** @return array<string,string> */
	private function submission(): array {
		return array(
			'hide_github'           => '0',
			'self_update_discovery' => '1',
			'history_rows'          => '2000',
			'history_days'          => '30',
			'max_download_bytes'    => '52428800',
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Narrow database double kept with its sole consumer.
final class GeneralPreferenceDatabase {
	public string $options                = 'wp_options';
	public string $last_error             = '';
	public bool $fail_writes              = false;
	public bool $fail_commit              = false;
	private ?string $transaction_original = null;
	public int $fresh_reads               = 0;

	public function prepare( string $query, mixed ...$arguments ): string {
		return $query . "\n" . serialize( $arguments ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Controlled test transport.
	}

	public function get_var( string $query ): ?string {
		if ( str_starts_with( $query, 'SELECT option_value FROM' ) ) {
			++$this->fresh_reads;
		}
		return $GLOBALS['ran_booster_general_preference_raw'];
	}

	public function query( string $query ): int|false {
		[$sql, $arguments] = explode( "\n", $query, 2 ) + array( 1 => serialize( array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Controlled test transport.
		$arguments         = unserialize( $arguments ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Controlled test transport.
		if ( 'START TRANSACTION' === $sql ) {
			$this->transaction_original = $GLOBALS['ran_booster_general_preference_raw'];
		}
		if ( 'COMMIT' === $sql && $this->fail_commit ) {
			$GLOBALS['ran_booster_general_preference_raw'] = $this->transaction_original;
			return false;
		}
		if ( $this->fail_writes && ( str_contains( $sql, 'INSERT INTO' ) || str_contains( $sql, 'UPDATE' ) ) ) {
			return false;
		}
		if ( str_contains( $sql, 'INSERT INTO' ) ) {
			$GLOBALS['ran_booster_general_preference_raw'] = $arguments[2];
		} elseif ( str_contains( $sql, 'UPDATE' ) && ( $GLOBALS['ran_booster_general_preference_raw'] ?? null ) === $arguments[3] ) {
			$GLOBALS['ran_booster_general_preference_raw'] = $arguments[1];
		}
		return 1;
	}
}

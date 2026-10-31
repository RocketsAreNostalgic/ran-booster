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
				'max_archive_bytes'     => '1048576',
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
				'max_archive_bytes'     => 1048576,
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
				array( 'max_archive_bytes' => '536870912' ),
			) as $change
		) {
			$input = array_merge( $values, $change );
			self::assertNull( $store->parse_submission( $input, array_keys( $values ) ) );
		}
		$values['history_rows']      = '200';
		$values['history_days']      = '365';
		$values['max_archive_bytes'] = '536870911';
		self::assertSame(
			array(
				'hide_github'           => false,
				'self_update_discovery' => true,
				'history_rows'          => 200,
				'history_days'          => 365,
				'max_archive_bytes'     => 536870911,
			),
			$store->parse_submission( $values, array_keys( $values ) )
		);
	}

	public function test_archive_mib_submission_rounds_new_values_and_preserves_existing_exact_bytes(): void {
		$store  = new GeneralPreferenceStore();
		$values = $this->submission();
		unset( $values['max_archive_bytes'] );

		$values['max_archive_mib'] = '50.5';
		$parsed                    = $store->parse_submission( $values, array_keys( $this->submission() ) );
		self::assertIsArray( $parsed );
		self::assertSame( 52953088, $parsed['max_archive_bytes'] );
		$values['max_archive_mib'] = '50.1';
		$parsed                    = $store->parse_submission( $values, array_keys( $this->submission() ) );
		self::assertIsArray( $parsed );
		self::assertSame( 52533658, $parsed['max_archive_bytes'] );

		$values['max_archive_mib'] = '50.00000095367431640625';
		$parsed                    = $store->parse_submission( $values, array_keys( $this->submission() ) );
		self::assertIsArray( $parsed );
		self::assertSame( 52428801, $parsed['max_archive_bytes'], 'An existing exact byte limit must round trip through its finite MiB representation.' );
		$this->seed( array( 'max_archive_bytes' => 52428801 ) );
		self::assertSame(
			'unchanged',
			$store->save( $store->snapshot()['token'], $values, static fn ( callable $operation ): string => $operation(), static fn (): bool => true )
		);
		self::assertSame( 52428801, \RAN\Settings\maybe_unserialize( $GLOBALS['ran_booster_general_preference_raw'] )['max_archive_bytes'] );

		foreach ( array( '0', '-1', '512', '5e1', '999999999999999999999999' ) as $mib ) {
			$values['max_archive_mib'] = $mib;
			self::assertNull( $store->parse_submission( $values, array_keys( $this->submission() ) ), $mib );
		}
	}

	public function test_unchanged_and_failed_writes_are_reported_honestly(): void {
		$store = new GeneralPreferenceStore();
		$token = $store->snapshot()['token'];
		self::assertSame( 'unchanged', $store->save( $token, $this->submission(), static fn ( callable $operation ): string => $operation(), static fn (): bool => true ) );
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
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', false );
		$this->seed(
			array(
				'self_update_discovery' => true,
				'history_rows'          => 300,
				'history_days'          => 7,
				'max_archive_bytes'     => 1048576,
			)
		);
		$store      = new GeneralPreferenceStore();
		$projection = $store->projection();

		self::assertTrue( $projection['self_update_discovery']['locked'] );
		self::assertFalse( $projection['self_update_discovery']['value'] );
		self::assertSame( 'invalid_code', $projection['history_rows']['error'] );
		self::assertSame( 'invalid_code', $projection['max_archive_bytes']['error'] );
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
				'max_archive_bytes'     => 1048576,
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
			'max_archive_bytes'     => '52428800',
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Narrow database double kept with its sole consumer.
final class GeneralPreferenceDatabase {
	public string $options    = 'wp_options';
	public string $last_error = '';
	public bool $fail_writes  = false;
	public int $fresh_reads   = 0;

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

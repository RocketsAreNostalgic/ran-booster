<?php

declare(strict_types=1);

use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\GitHubSelectionStore;
use RAN\WordPress\WordPressUpdaterLock;

// Installed storage/composition proof; no real plugin is activated and runtime bootstrap stays unchanged.
return static function ( wpdb $database ): void {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		throw new RuntimeException( 'Selection SQL proof requires installed WordPress database configuration.' );
	}
	$table        = $database->prefix . 'ran_selection_smoke_' . bin2hex( random_bytes( 6 ) );
	$other        = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$store        = new GitHubSelectionStore( $database, $table );
	$second       = new GitHubSelectionStore( $other, $table );
	$expect       = static function ( bool $condition, string $reason ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed developer-authored proof failure, not rendered HTML.
			throw new RuntimeException( 'Selection SQL proof: ' . $reason );
		}
	};
	$query        = static function ( ?string $sql ) use ( $database ): void {
		if ( null === $sql || false === $database->query( $sql ) ) {
			throw new RuntimeException( 'Selection SQL fixture mutation failed.' );
		}
	};
	$reject       = static function ( callable $operation, string $reason ) use ( $expect ): void {
		try {
			$operation();
		} catch ( DatabaseLifecycleFailure $failure ) {
			$expect( '' !== $failure->reason(), 'typed failure has a reason' );
			return;
		}
		$expect( false, $reason );
	};
	$declaration  = new GitHubReplacementDeclaration(
		array(
			'plugin_basename'        => 'ran-booster/ran-booster.php',
			'implementation_id'      => 'bundled-storage-proof',
			'compatibility_revision' => '1',
			'provider_api_version'   => 15,
			'api_credential_formats' => array( 'github-classic-pat-v1' ),
			'webhook_signing_format' => null,
			'facets'                 => array(),
		)
	);
	$saved_cache  = array();
	$plugin_root  = null;
	$plugin_files = array();
	foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions', 'active_plugins' ) as $key ) {
		$found               = false;
		$value               = wp_cache_get( $key, 'options', false, $found );
		$saved_cache[ $key ] = array( $found, $value );
	}
	try {
		// Clone the installed options shape, but never change the site's real selection row.
		$query( $database->prepare( 'CREATE TABLE %i LIKE %i', $table, $database->options ) );
		$reject( static fn () => $store->read(), 'missing selection must refuse' );
		wp_cache_set( GitHubSelectionStore::OPTION, 'stale-selection', 'options' );
		wp_cache_set( 'notoptions', array( GitHubSelectionStore::OPTION => true ), 'options' );
		wp_cache_set( 'alloptions', array( GitHubSelectionStore::OPTION => 'stale-selection' ), 'options' );
		$query( 'START TRANSACTION' );
		$original = $store->initialize_bundled( $declaration );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$expect( 0 === $original['selection_generation'] && 'bundled' === $original['selected'], 'fresh bundled selection' );
		$expect( $original === $second->read(), 'independent connection sees initialization' );
		$expect( 'no' === $database->get_var( $database->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $table, GitHubSelectionStore::OPTION ) ), 'selection never autoloads' );
		foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$expect( ! $found, 'initialization invalidates stale option caches' );
		}
		$next                         = $original;
		$next['selection_generation'] = 1;
		$next['approval']             = 'revoked';
		$query( 'START TRANSACTION' );
		$expect( $original === $store->read( true ), 'transaction reads locked selection' );
		$expect( $store->compare_and_swap( $original, $next ), 'transactional selection write' );
		$expect( $next === $store->read(), 'writer sees uncommitted replacement' );
		$expect( $original === $second->read(), 'another connection cannot see uncommitted selection' );
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$expect( $original === $store->read() && $original === $second->read(), 'caller rollback restores selection; store does not commit' );

		// Simulate storage changing the written bytes before authoritative readback.
		$query( 'START TRANSACTION' );
		$expect( $original === $store->read( true ), 'readback fixture holds row lock' );
		$disturbed = false;
		$disturb   = static function ( string $sql ) use ( &$disturbed, $table, $database, $query ): string {
			if ( ! $disturbed && str_contains( $sql, 'SELECT option_name, option_value, autoload FROM' ) && str_contains( $sql, $table ) ) {
				$disturbed = true;
				$query( $database->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $table, '{changed-after-write', GitHubSelectionStore::OPTION ) );
			}
			return $sql;
		};
		add_filter( 'query', $disturb );
		try {
			$reject( static fn () => $store->compare_and_swap( $original, $next ), 'changed readback must not report successful write' );
		} finally {
			remove_filter( 'query', $disturb );
		}
		$expect( $disturbed, 'actual SQL readback was interleaved' );
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$expect( $original === $second->read(), 'failed readback remains caller-rollbackable' );

		// B commits after A captures its expectation. A must not overwrite B or trust its cached row.
		$stale = $store->read();
		$expect( false !== $other->query( 'START TRANSACTION' ), 'second connection begins its transaction' );
		$expect( $original === $second->read( true ), 'second connection locks its selection' );
		$expect( $second->compare_and_swap( $original, $next ), 'second connection advances selection' );
		// A cache reader can repopulate the old committed value after CAS invalidation.
		// This exercises WordPress's cache API; it does not qualify an external Redis backend.
		$expect( $original === $store->read(), 'first connection still sees old committed row' );
		wp_cache_set( GitHubSelectionStore::OPTION, 'old-committed-selection', 'options' );
		wp_cache_set( 'alloptions', array( GitHubSelectionStore::OPTION => 'old-committed-selection' ), 'options' );
		wp_cache_set( 'notoptions', array( GitHubSelectionStore::OPTION => true ), 'options' );
		$expect( false !== $other->query( 'COMMIT' ), 'caller commits second connection' );
		$expect( 'old-committed-selection' === wp_cache_get( GitHubSelectionStore::OPTION, 'options' ), 'commit alone does not clear repopulated cache' );
		$second->invalidate_cache();
		foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$expect( ! $found, 'caller postcommit invalidation removes repopulated cache' );
		}
		$expect( $next === $store->read(), 'first connection sees committed selection' );
		wp_cache_set( GitHubSelectionStore::OPTION, 'stale-selection', 'options' );
		wp_cache_set( 'alloptions', array( GitHubSelectionStore::OPTION => 'stale-selection' ), 'options' );
		wp_cache_set( 'notoptions', array( GitHubSelectionStore::OPTION => true ), 'options' );
		$query( 'START TRANSACTION' );
		$expect( $next === $store->read( true ), 'stale writer locks current committed selection' );
		$expect( ! $store->compare_and_swap( $stale, $next ), 'stale cross-connection compare-and-swap refuses' );
		$expect( $next === $store->read(), 'direct read bypasses stale option cache' );
		foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$expect( ! $found, 'stale compare-and-swap invalidates option caches' );
		}
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$query( 'START TRANSACTION' );
		$expect( $next === $store->initialize_bundled( $declaration ), 'repeat initialization preserves durable revoked selection' );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$expect( 1 === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'initialization leaves exactly one row' );

		// Exercise the real SQL-backed request context against private passive files.
		// These files are not under WP_PLUGIN_DIR and their PHP is never executed.
		$temp = realpath( sys_get_temp_dir() );
		$expect( is_string( $temp ), 'private temporary root is available' );
		$candidate_root = $temp . '/ran-selection-smoke-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exclusive disposable passive fixture, never an installed plugin directory.
		$expect( mkdir( $candidate_root, 0700 ), 'private fixture root created' );
		$plugin_root = $candidate_root;
		$expect( ! is_link( $plugin_root ), 'private fixture root is not a symlink' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- One exact disposable fixture directory.
		$expect( mkdir( $plugin_root . '/ran-booster', 0700 ), 'bundled fixture directory created' );
		$write = static function ( string $basename, string $bytes ) use ( $plugin_root, &$plugin_files, $expect ): void {
			$path                  = $plugin_root . '/' . $basename;
			$plugin_files[ $path ] = true;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Bounded passive files under the exclusively created private fixture root.
			$expect( strlen( $bytes ) === file_put_contents( $path, $bytes ), 'passive fixture bytes written' );
		};
		$write( $declaration->plugin_basename, '<?php /* Plugin Name: Bundled fixture */' );
		/** @param list<string> $plugins */
		$set_active = static function ( array $plugins ) use ( $database, $table, $query ): void {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Actual WordPress active_plugins wire format with a closed list of fixture strings, no objects.
			$query( $database->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES ('active_plugins', %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $table, serialize( $plugins ) ) );
		};
		$set_active( array( $declaration->plugin_basename ) );
		$restored                         = $next;
		$restored['selection_generation'] = 2;
		$restored['approval']             = 'approved';
		$query( 'START TRANSACTION' );
		$expect( $next === $store->read( true ) && $store->compare_and_swap( $next, $restored ), 'restore approved fixture selection' );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$inert_factory       = static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $registration ): RepositoryProvider {
			unset( $credentials, $evidence, $registration );
			return new class() implements RepositoryProvider {
				public function get_metadata(): \RAN\RepositoryProvider\ProviderMetadata {
					return new \RAN\RepositoryProvider\ProviderMetadata( \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ), 'Passive SQL fixture', 'https://github.com/', 'Owner' );
				}
				public function get_provider_diagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
					return new class() implements \RAN\RepositoryProvider\ProviderDiagnostics {
						public function diagnose( \RAN\RepositoryProvider\ProviderDiagnosticRequest $request ): array {
							unset( $request );
							return array();
						}
					};
				}
				public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
					unset( $request );
					throw new RuntimeException( 'Passive proof must never perform repository I/O.' );
				}
				public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
					unset( $request );
					throw new RuntimeException( 'Passive proof must never prepare an archive.' );
				}
			};
		};
		$bind                = static function ( GitHubProviderSelection $context, bool $declare_candidates = true, bool $finish = true ) use ( $inert_factory ): ProviderRegistry {
			$registry = new ProviderRegistry(
				credential_store_factory: static fn (): ProviderCredentialStore => ( new \RAN\Secrets\SecretsFile() )->credentials_for( 'gh' ),
				delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => new \RAN\RepositoryProvider\ProviderBoundWebhookDeliveryEvidenceReader( \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ), static fn (): null => null ),
				github_selection_guard: array( $context, 'registry_selection' ),
				github_candidates: $context->candidates(),
				bundled_github_factory: $inert_factory
			);
			$context->bind_registry( $registry );
			if ( $declare_candidates ) {
				foreach ( $context->candidates() as $candidate ) {
					if ( null !== $candidate ) {
						$registry->declare_github_replacement( $candidate, $inert_factory );
					}
				}
			}
			if ( $finish ) {
				$context->complete_collection();
				$registry->seal();
			}
			return $registry;
		};
		$compose             = static function () use ( $store, $plugin_root, $declaration, $bind ): GitHubProviderSelection {
			$context = new GitHubProviderSelection( $store, $plugin_root, $declaration );
			$bind( $context );
			return $context;
		};
		$context             = new GitHubProviderSelection( $store, $plugin_root, $declaration );
		$incomplete_registry = $bind( $context, true, false );
		$expect( ! $context->operation_allowed(), 'incomplete public declaration window has no authority' );
		$context->complete_collection();
		$incomplete_registry->seal();
		$expect( $context->operation_allowed(), 'installed approved bundled context is usable after actual registry publication' );
		$lock_proof = require __DIR__ . '/github-selection-lock-smoke.php';
		$lock_proof( $database, $table, $context, $plugin_root );

		$fake_active = static fn (): array => array( 'not-installed.php' );
		add_filter( 'pre_option_active_plugins', $fake_active );
		wp_cache_set( 'active_plugins', array( 'cached-missing.php' ), 'options' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Deliberately stale WordPress cache uses the real string-list wire format.
		wp_cache_set( 'alloptions', array( 'active_plugins' => serialize( array( 'cached-missing.php' ) ) ), 'options' );
		try {
			$expect( array( 'not-installed.php' ) === get_option( 'active_plugins' ), 'WordPress filtered read is intentionally different' );
			$expect( array( $declaration->plugin_basename ) === $store->active_plugins(), 'active authority bypasses WordPress cache and option filters' );
			$expect( $context->operation_allowed(), 'stale cache and filter do not replace durable active authority' );
		} finally {
			remove_filter( 'pre_option_active_plugins', $fake_active );
		}
		$write( 'unrelated.php', '<?php /* Plugin Name: Unrelated fixture */' );
		$set_active( array( $declaration->plugin_basename, 'unrelated.php' ) );
		$expect( ! $context->operation_allowed(), 'same request refuses actual active-list change' );
		$expect( $compose()->operation_allowed(), 'fresh request can use unchanged bundled contract after unrelated activation' );
		$set_active( array( $declaration->plugin_basename ) );

		$manifest = get_object_vars( $declaration );
		unset( $manifest['implementation_fingerprint'] );
		$manifest['plugin_basename']   = 'a.php';
		$manifest['implementation_id'] = 'replacement-a-proof';
		$a                             = new GitHubReplacementDeclaration( $manifest );
		$write( 'a.php', "<?php\n/*\nRAN Booster GitHub Replacement: 1\n*/" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact passive manifest proof bytes must throw on invalid data.
		$write( 'a.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		$set_active( array( $declaration->plugin_basename, 'a.php' ) );
		$expect( ! $compose()->operation_allowed(), 'candidate activation alone does not approve replacement' );
		$adopt = static function ( GitHubReplacementDeclaration $target ) use ( $store, $query, $expect ): void {
			$query( 'START TRANSACTION' );
			$old = $store->read( true );
			if ( ! is_int( $old['selection_generation'] ) ) {
				throw new RuntimeException( 'Fixture selection generation is malformed.' );
			}
			$new                         = $old;
			$new['selection_generation'] = $old['selection_generation'] + 1;
			$new['selected']             = 'replacement';
			$new['declaration']          = get_object_vars( $target );
			unset( $new['declaration']['implementation_fingerprint'] );
			$new['approved_api_formats'] = $target->api_credential_formats;
			$expect( $store->compare_and_swap( $old, $new ), 'explicit fixture authority transition' );
			$query( 'COMMIT' );
			$store->invalidate_cache();
		};
		$adopt( $a );
		$preview       = $compose();
		$expectation_a = $preview->expectation();
		$expect( null !== $expectation_a, 'approved A produces review expectation' );
		$missing_context  = new GitHubProviderSelection( $store, $plugin_root, $declaration );
		$missing_callback = $bind( $missing_context, false );
		$expect( array() === $missing_callback->all() && ! $missing_context->operation_allowed(), 'passive selected manifest without public callback publishes no provider or operation authority' );
		$expect( 'provider_unavailable' === $missing_context->review_refusal( $expectation_a ), 'missing callback refuses a matching review expectation' );

		$write( 'a.php', "<?php\n/*\nVersion: 99.0\nRAN Booster GitHub Replacement: 1\n*/" );
		$expect( $preview->operation_allowed(), 'ordinary plugin version change preserves semantic approval' );
		$manifest['compatibility_revision'] = '2';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact passive manifest proof bytes.
		$write( 'a.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		$expect( ! $preview->operation_allowed(), 'same request refuses semantic file drift with unchanged active list' );
		$manifest['compatibility_revision'] = '1';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact passive manifest proof bytes.
		$write( 'a.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		$expect( $preview->operation_allowed(), 'restored exact approved semantic declaration resumes' );

		$issued        = 0;
		$factory_calls = 0;
		$old_request   = new GitHubProviderSelection( $store, $plugin_root, $declaration );
		$old_registry  = new ProviderRegistry(
			credential_store_factory: static function () use ( &$issued ): ProviderCredentialStore {
				++$issued;
				throw new RuntimeException( 'Stale credential issuance.' );
			},
			delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => new \RAN\RepositoryProvider\ProviderBoundWebhookDeliveryEvidenceReader( \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ), static fn (): null => null ),
			github_selection_guard: array( $old_request, 'registry_selection' ),
			github_candidates: $old_request->candidates()
		);
		$old_request->bind_registry( $old_registry );
		$old_request->complete_collection();
		$old_registry->declare_github_replacement(
			$a,
			static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $registration ) use ( &$factory_calls ): RepositoryProvider {
				unset( $credentials, $evidence, $registration );
				++$factory_calls;
				throw new RuntimeException( 'Stale provider construction.' );
			}
		);
		$expect( null !== $old_request->registry_selection(), 'old registry has valid A construction authority before switching' );
		$manifest['plugin_basename']   = 'b.php';
		$manifest['implementation_id'] = 'replacement-b-proof';
		$b                             = new GitHubReplacementDeclaration( $manifest );
		$write( 'b.php', "<?php\n/*\nRAN Booster GitHub Replacement: 1\n*/" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact passive manifest proof bytes.
		$write( 'b.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		$set_active( array( $declaration->plugin_basename, 'b.php' ) );
		$adopt( $b );
		$apply_request = $compose();
		$expectation_b = $apply_request->expectation();
		$expect( null !== $expectation_b && $expectation_a !== $expectation_b, 'new request B cannot reuse A review identity' );
		$expect( null === $preview->expectation(), 'old A request loses authority after B selection' );
		$old_registry->seal();
		$expect( 0 === $issued && 0 === $factory_calls && array() === $old_registry->all(), 'stale A composition refuses before credential issuance and provider factory' );
		$expect( 'provider_selection_changed' === $apply_request->review_refusal( $expectation_a ), 'actual new-request guard refuses old A review before any provider operation' );
		$expect( null === $apply_request->review_refusal( $expectation_b ), 'current exact B expectation passes the identity guard' );
		// Blueprint Apply must still integrate this refusal before its own effects;
		// this is the actual guard and registry proof, not consumer end-to-end delivery.
		$set_active( array( $declaration->plugin_basename, 'a.php', 'b.php' ) );
		$expect( ! $compose()->operation_allowed(), 'multiple candidates conflict without ranking' );
		$set_active( array( $declaration->plugin_basename, 'b.php', 'missing.php' ) );
		$expect( ! $compose()->operation_allowed(), 'unreadable active main remains an invalid candidate' );
		$set_active( array( $declaration->plugin_basename, 'b.php' ) );
		$manifest['provider_api_version'] = 14;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deliberately incompatible passive manifest proof bytes.
		$write( 'b.gh-replacement.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
		$expect( ! $apply_request->operation_allowed() && ! $compose()->operation_allowed(), 'incompatible selected manifest pauses old and new requests' );

		// Prove real wpdb + mysqli pinned stale-lock reclaim bypasses WordPress query filters,
		// enforces native_mutation holds, refuses transparent reconnect retries after KILL, and
		// fails closed on a closed wpdb connection.
		$saved_wpdb    = $GLOBALS['wpdb'] ?? null;
		$saved_options = $database->options;
		$doomed        = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$closed        = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock          = new WordPressUpdaterLock();
		$stale         = (string) ( time() - 7200 );
		$lock_row      = static fn () => $other->get_var( $other->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
		try {
			$database->options = $table;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily bind the disposable smoke table connection during the installed lock proof and restore $GLOBALS['wpdb'] in finally.
			$GLOBALS['wpdb'] = $database;

			$reclaim_context = new GitHubProviderSelection( $store, $plugin_root, $declaration );
			$expect( array() !== $store->read(), 'warm selection storage check before pinned reclaim' );
			$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			$query( $database->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'auto_updater.lock', $stale, 'no' ) );

			/** @var ArrayObject<int, string> $filtered_queries */
			$filtered_queries = new ArrayObject();
			$record_queries   = static function ( string $sql ) use ( $filtered_queries ): string {
				$filtered_queries->append( $sql );
				return $sql;
			};
			add_filter( 'query', $record_queries );
			$committed_reclaim = null;
			try {
				$reclaimed = $reclaim_context->without_native_mutation_hold(
					fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $reclaim_context->uses_connection( ... ), $execute ),
					static function ( ?string $token ) use ( &$committed_reclaim ): void {
						$committed_reclaim = $token;
					}
				);
			} finally {
				remove_filter( 'query', $record_queries );
			}
			$expect( 0 === $filtered_queries->count(), 'pinned wpdb stale-lock reclaim must not run WordPress query filters inside the transaction' );
			$expect( is_string( $reclaimed ) && $reclaimed !== $stale && $committed_reclaim === $reclaimed && $lock_row() === $reclaimed, 'pinned wpdb stale-lock reclaim must commit exact token' );

			$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			$query( $database->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'auto_updater.lock', $stale, 'no' ) );
			$held_states = $store->selection_transaction(
				static function () use ( $store, $expect ): array {
					$before_hold                  = $store->read( true );
					$with_hold                    = $before_hold;
					$with_hold['native_mutation'] = array(
						'selection_generation' => $before_hold['selection_generation'],
						'owner_tag'            => str_repeat( '0', 16 ) . str_repeat( 'a', 16 ),
						'target_type'          => 'plugin',
						'installed_identity'   => 'held-smoke/held-smoke.php',
						'phase'                => 'started',
					);
					$expect( $store->compare_and_swap( $before_hold, $with_hold ), 'seed active native_mutation hold for smoke probe' );
					return array( $before_hold, $with_hold );
				}
			);
			try {
				$expect( array() !== $store->read(), 'warm selection storage check before held reclaim probe' );
				$filtered_queries->exchangeArray( array() );
				add_filter( 'query', $record_queries );
				$held_callback = false;
				try {
					$held_result = $reclaim_context->without_native_mutation_hold(
						fn ( callable $execute ): ?string => $lock->acquire_or_reclaim_expired_sql( $reclaim_context->uses_connection( ... ), $execute ),
						static function () use ( &$held_callback ): void {
							$held_callback = true;
						}
					);
				} finally {
					remove_filter( 'query', $record_queries );
				}
				$expect( null === $held_result && ! $held_callback, 'active native_mutation hold must skip reclaim and post-commit callback' );
				$expect( 0 === $filtered_queries->count(), 'held native_mutation check must stay callback-free on pinned wpdb' );
				$expect( $stale === $lock_row(), 'held native_mutation check must preserve expired updater lock row' );
			} finally {
				$store->selection_transaction(
					static function () use ( $store, $held_states, $expect ): void {
						$expect( $store->compare_and_swap( $held_states[1], $held_states[0] ), 'clear seeded native_mutation hold after smoke probe' );
					}
				);
			}

			$doomed->options = $table;
			$doomed_store    = new GitHubSelectionStore( $doomed, $table );
			$doomed_context  = new GitHubProviderSelection( $doomed_store, $plugin_root, $declaration );
			$doomed_id       = (int) $doomed->get_var( 'SELECT CONNECTION_ID()' );
			$expect( $doomed_id > 0, 'doomed wpdb connection has valid MySQL session id' );
			$expect( array() !== $doomed_store->read(), 'warm doomed store storage verification before pinning session' );

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bind doomed wpdb session for real MySQL KILL test and restore in finally.
			$GLOBALS['wpdb']   = $doomed;
			$doomed_suppressed = $doomed->suppress_errors( true );
			$filtered_queries->exchangeArray( array() );
			$killed_callback = false;
			$killed_rejected = false;
			add_filter( 'query', $record_queries );
			try {
				try {
					$doomed_context->without_native_mutation_hold(
						static function ( callable $execute ) use ( $doomed_context, $lock, $other, $doomed_id, $expect ): ?string {
							$kill_sql = $other->prepare( 'KILL %d', $doomed_id );
							$expect( is_string( $kill_sql ) && false !== $other->query( $kill_sql ), 'control connection kills doomed session inside open transaction' );
							$deadline = microtime( true ) + 2.0;
							while ( microtime( true ) < $deadline ) {
								if ( 0 === (int) $other->get_var( $other->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $doomed_id ) ) ) {
									break;
								}
								usleep( 10000 );
							}
							$expect( 0 === (int) $other->get_var( $other->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $doomed_id ) ), 'killed doomed session must exit before reclaim executes' );
							return $lock->acquire_or_reclaim_expired_sql( $doomed_context->uses_connection( ... ), $execute );
						},
						static function () use ( &$killed_callback ): void {
							$killed_callback = true;
						}
					);
				} catch ( DeploymentStorageFailure ) {
					$killed_rejected = true;
				}
			} finally {
				remove_filter( 'query', $record_queries );
				$doomed->suppress_errors( $doomed_suppressed );
			}
			$expect( $killed_rejected && ! $killed_callback, 'killed session reclaim must fail closed without invoking committed callback' );
			$expect( $stale === $lock_row(), 'killed session reclaim must preserve expired lock without autocommit retry' );
			foreach ( $filtered_queries as $filtered_sql ) {
				$expect( str_starts_with( $filtered_sql, 'KILL ' ) || str_contains( $filtered_sql, 'information_schema.PROCESSLIST' ), 'killed session must not trigger wpdb reconnect or table queries through query filter' );
			}
			$reject(
				static fn () => $doomed_context->without_native_mutation_hold( static fn (): null => null ),
				'killed session must leave selection store uncertain and refuse reuse'
			);

			$closed->options = $table;
			$closed_store    = new GitHubSelectionStore( $closed, $table );
			$closed_context  = new GitHubProviderSelection( $closed_store, $plugin_root, $declaration );
			$expect( array() !== $closed_store->read(), 'warm closed store before closing handle' );
			$closed->close();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bind closed wpdb instance to verify fail-closed pinned handle check before restoring in finally.
			$GLOBALS['wpdb'] = $closed;
			$filtered_queries->exchangeArray( array() );
			add_filter( 'query', $record_queries );
			try {
				$reject(
					static fn () => $closed_context->without_native_mutation_hold(
						static function (): ?string {
							throw new RuntimeException( 'Closed handle operation must not execute.' );
						}
					),
					'closed wpdb handle must fail before opening transaction or executing operation'
				);
			} finally {
				remove_filter( 'query', $record_queries );
			}
			$expect( 0 === $filtered_queries->count(), 'closed handle check must not run query filters' );
		} finally {
			$database->options = $saved_options;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore WordPress global wpdb after isolated smoke proof.
			$GLOBALS['wpdb'] = $saved_wpdb;
			$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			$doomed->close();
			$closed->close();
		}

		$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'active_plugins' ) );
		$reject( static fn () => $store->active_plugins(), 'missing active authority must refuse rather than assume no candidates' );

		$query( $database->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $table, '{broken', GitHubSelectionStore::OPTION ) );
		$reject( static fn () => $store->read(), 'corrupt state refuses' );
		$query( 'START TRANSACTION' );
		$reject( static fn () => $store->initialize_bundled( $declaration ), 'initialization cannot repair corruption silently' );
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$expect( '{broken' === $database->get_var( $database->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, GitHubSelectionStore::OPTION ) ), 'corrupt bytes preserved for diagnosis' );
		$query( $database->prepare( 'DELETE FROM %i', $table ) );
		$query( 'START TRANSACTION' );
		$store->initialize_bundled( $declaration );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$query( $database->prepare( "UPDATE %i SET autoload = 'yes'", $table ) );
		$reject( static fn () => $store->read(), 'autoloaded state refuses' );
		$query( $database->prepare( "UPDATE %i SET autoload = 'no'", $table ) );
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) );
		$store->invalidate_cache();
		$reject( static fn () => $store->read(), 'nontransactional options storage refuses' );
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		$query( $database->prepare( 'ALTER TABLE %i DROP INDEX option_name', $table ) );
		$store->invalidate_cache();
		$reject( static fn () => $store->read(), 'missing unique option-name key refuses' );
		$query( $database->prepare( 'ALTER TABLE %i ADD UNIQUE KEY option_name (option_name(10))', $table ) );
		$store->invalidate_cache();
		$reject( static fn () => $store->read(), 'prefix-only unique option-name key refuses' );
	} finally {
		$database->query( 'ROLLBACK' );
		$other->query( 'ROLLBACK' );
		$store->invalidate_cache();
		foreach ( array_keys( $plugin_files ) as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact private fixture files recorded when created, never installed plugin paths.
				unlink( $path );
			}
		}
		if ( null !== $plugin_root ) {
			if ( is_dir( $plugin_root . '/ran-booster' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty exclusively created fixture directory.
				rmdir( $plugin_root . '/ran-booster' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty exclusively created fixture root.
			rmdir( $plugin_root );
		}

		$query( $database->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		foreach ( $saved_cache as $key => $entry ) {
			wp_cache_delete( $key, 'options' );
			if ( $entry[0] ) {
				wp_cache_set( $key, $entry[1], 'options' );
			}
		}
		$other->close();
	}
};

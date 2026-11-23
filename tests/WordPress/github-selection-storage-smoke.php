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
	foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions', 'active_plugins', 'auto_updater.lock' ) as $key ) {
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

		// Prove pinned native_mutation begin and completion run zero WordPress query/option hooks.
		$admission_wpdb    = $GLOBALS['wpdb'] ?? null;
		$admission_options = $database->options;
		$admission_lock    = new WordPressUpdaterLock();
		$admission_token   = (string) time();
		/** @var ArrayObject<int, string> $admission_queries */
		$admission_queries    = new ArrayObject();
		$record_admission_sql = static function ( string $sql ) use ( $admission_queries ): string {
			$admission_queries->append( $sql );
			return $sql;
		};
		/** @var ArrayObject<int, true> $admission_option_hits */
		$admission_option_hits = new ArrayObject();
		$record_admission_opt  = static function ( mixed $value = null ) use ( $admission_option_hits ): mixed {
			$admission_option_hits->append( true );
			return $value;
		};
		$admission_opt_hooks   = array(
			'pre_option_auto_updater.lock',
			'option_auto_updater.lock',
			'pre_option_active_plugins',
			'option_active_plugins',
			'pre_option_ran_booster_github_selection',
			'option_ran_booster_github_selection',
		);
		try {
			$database->options = $table;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily bind the smoke table connection during the isolated native-admission proof and restore in finally.
			$GLOBALS['wpdb'] = $database;
			$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			$query( $database->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'auto_updater.lock', $admission_token, 'no' ) );
			$expect( array() !== $store->read(), 'warm selection store before hook-free native-admission proof' );
			$admitted_owner = null;
			add_filter( 'query', $record_admission_sql );
			foreach ( $admission_opt_hooks as $hook_name ) {
				add_filter( $hook_name, $record_admission_opt );
			}
			try {
				$owner_tag = $context->begin_native_mutation(
					'plugin',
					'admitted-smoke/admitted-smoke.php',
					static fn ( callable $execute ): bool => $admission_token === $admission_lock->current_token_sql( $context->uses_connection( ... ), $execute ),
					$admission_token,
					static function ( string $committed_owner ) use ( &$admitted_owner ): void {
						$admitted_owner = $committed_owner;
					}
				);
			} finally {
				foreach ( $admission_opt_hooks as $hook_name ) {
					remove_filter( $hook_name, $record_admission_opt );
				}
				remove_filter( 'query', $record_admission_sql );
			}
			$expect( 0 === $admission_queries->count(), 'pinned begin_native_mutation must run zero WordPress query filters inside the transaction' );
			$expect( 0 === $admission_option_hits->count(), 'pinned begin_native_mutation must run zero WordPress option hooks inside the transaction' );
			$admitted_state = $store->read();
			$admitted_hold  = $admitted_state['native_mutation'] ?? null;
			$expect(
				$admitted_owner === $owner_tag
					&& is_array( $admitted_hold )
					&& 'started' === $admitted_hold['phase']
					&& GitHubProviderSelection::native_lock_token( $admitted_hold ) === $admission_token,
				'pinned begin_native_mutation recorded exact started hold and owner tag'
			);

			$admission_queries->exchangeArray( array() );
			$admission_option_hits->exchangeArray( array() );
			add_filter( 'query', $record_admission_sql );
			foreach ( $admission_opt_hooks as $hook_name ) {
				add_filter( $hook_name, $record_admission_opt );
			}
			try {
				$context->complete_native_mutation( $owner_tag, 'plugin', 'admitted-smoke/admitted-smoke.php', 'verified_success' );
			} finally {
				foreach ( $admission_opt_hooks as $hook_name ) {
					remove_filter( $hook_name, $record_admission_opt );
				}
				remove_filter( 'query', $record_admission_sql );
			}
			$expect( 0 === $admission_queries->count(), 'pinned complete_native_mutation must run zero WordPress query filters inside the transaction' );
			$expect( 0 === $admission_option_hits->count(), 'pinned complete_native_mutation must run zero WordPress option hooks inside the transaction' );
			$expect( ! array_key_exists( 'native_mutation', $store->read() ), 'hook-free native completion cleared admitted hold cleanly' );
		} finally {
			$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			$database->options = $admission_options;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore WordPress global wpdb after isolated native-admission proof.
			$GLOBALS['wpdb'] = $admission_wpdb;
		}

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

			$prime_lock_cache  = static function (): void {
				wp_cache_set( 'auto_updater.lock', 'smoke-lock-cache', 'options' );
				wp_cache_set( 'notoptions', array( 'smoke-lock-cache' => true ), 'options' );
			};
			$expect_lock_cache = static function ( bool $expected, string $reason ) use ( $expect ): void {
				foreach ( array( 'auto_updater.lock', 'notoptions' ) as $cache_key ) {
					$found = false;
					wp_cache_get( $cache_key, 'options', false, $found );
					$expect( $expected === $found, $reason );
				}
			};
			$expected_owner    = (string) time();
			$stale_base        = (string) ( time() - 3601 );
			foreach (
				array(
					array( $expected_owner, $expected_owner . ' ' ),
					array( $stale_base, $stale_base . ' ' ),
					array( $expected_owner, $stale_base . ' ' ),
				) as list( $candidate_owner, $padded_malformed )
			) {
				$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
				$query( $database->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'auto_updater.lock', $padded_malformed, 'no' ) );
				try {
					$prime_lock_cache();
					$expect_lock_cache( true, 'prime lock cache before trailing-space handoff check' );
					$padded_rejected = false;
					try {
						$lock->acquire_replacing_owner( $candidate_owner );
					} catch ( RuntimeException $failure ) {
						$padded_rejected = 'The WordPress updater is already running.' === $failure->getMessage();
					}
					$expect( $padded_rejected, 'byte-exact handoff must refuse trailing-space malformed lock on real options collation' );
					$expect( $padded_malformed === $lock_row(), 'byte-exact handoff must preserve trailing-space malformed lock bytes' );
					$expect_lock_cache( true, 'refused trailing-space handoff must keep option cache intact' );
				} finally {
					$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $table, 'auto_updater.lock', $padded_malformed ) );
				}
				$expect( null === $lock_row(), 'clean up trailing-space malformed lock fixture' );
			}

			$fixed_now         = time();
			$own               = (string) $fixed_now;
			$expected_recovery = (string) ( $fixed_now + 1 );
			$fixed_lock        = new class( $fixed_now ) extends WordPressUpdaterLock {
				public function __construct( private readonly int $fixed_now ) {}

				protected function now(): int {
					return $this->fixed_now;
				}
			};
			$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			$query( $database->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'auto_updater.lock', $own, 'no' ) );
			try {
				$prime_lock_cache();
				$acquired = $fixed_lock->acquire_replacing_owner( $own );
				$expect( $expected_recovery === $acquired && $expected_recovery === $lock_row() && $expected_recovery === $lock->current_token(), 'same-second handoff must atomically install distinct recovery token' );
				$expect_lock_cache( false, 'successful same-second handoff must invalidate option cache' );
				$prime_lock_cache();
				$concurrent_refused = false;
				try {
					$fixed_lock->acquire_replacing_owner( $own );
				} catch ( RuntimeException $failure ) {
					$concurrent_refused = 'The WordPress updater is already running.' === $failure->getMessage();
				}
				$expect( $concurrent_refused, 'concurrent same-second recovery against replaced owner must be refused' );
				$expect( $expected_recovery === $lock_row(), 'refused concurrent recovery must preserve active recovery lock' );
				$expect_lock_cache( true, 'refused concurrent recovery must leave option cache intact' );
				$expect( $lock->release( $expected_recovery ), 'release active recovery lock cleanly' );
				$expect( null === $lock_row(), 'releasing active recovery lock removes auto_updater.lock' );
			} finally {
				$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
			}

			$update_injected   = false;
			$update_filter     = static function ( string $sql ) use ( $table, &$update_injected ): string {
				if ( str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, $table ) && str_contains( $sql, 'auto_updater.lock' ) ) {
					$update_injected = true;
					return 'SELECT /* smoke updater lock update failure */ FROM';
				}
				return $sql;
			};
			$suppressed_update = $database->suppress_errors( true );
			add_filter( 'query', $update_filter );
			$update_failed_safely = false;
			try {
				$lock->acquire_replacing_owner( $expected_owner );
			} catch ( DeploymentStorageFailure $failure ) {
				$update_failed_safely = 'RAN Booster could not safely store deployment state.' === $failure->getMessage();
			} finally {
				remove_filter( 'query', $update_filter );
				$database->suppress_errors( $suppressed_update );
			}
			$expect( $update_injected && $update_failed_safely, 'UPDATE failure in acquire_replacing_owner must translate to Core storage failure' );

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

			$doomed_hold                  = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$reconnected_hold             = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$before_hold                  = $second->read();
			$with_hold                    = $before_hold;
			$with_hold['native_mutation'] = array(
				'selection_generation' => $before_hold['selection_generation'],
				'owner_tag'            => str_repeat( '0', 16 ) . str_repeat( 'b', 16 ),
				'target_type'          => 'plugin',
				'installed_identity'   => 'held-commit-smoke/held-commit-smoke.php',
				'phase'                => 'started',
			);
			$second->selection_transaction(
				static function () use ( $second, $before_hold, $with_hold, $expect ): void {
					$expect( $second->compare_and_swap( $before_hold, $with_hold ), 'seed active native_mutation hold for pinned session proofs' );
				}
			);
			try {
				$doomed_hold->options = $table;
				$doomed_hold_store    = new GitHubSelectionStore( $doomed_hold, $table );
				$doomed_hold_id       = (int) $doomed_hold->get_var( 'SELECT CONNECTION_ID()' );
				$expect( $doomed_hold_id > 0, 'doomed hold-clear wpdb connection has valid MySQL session id' );
				$expect( array() !== $doomed_hold_store->read(), 'warm doomed hold-clear store storage check before pinning session' );

				wp_cache_set( GitHubSelectionStore::OPTION, 'sentinel-before-kill', 'options' );
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bind doomed wpdb session for pinned hold-clear KILL test and restore in finally.
				$GLOBALS['wpdb']        = $doomed_hold;
				$doomed_hold_suppressed = $doomed_hold->suppress_errors( true );
				$filtered_queries->exchangeArray( array() );
				$hold_committed = false;
				$hold_rejected  = null;
				add_filter( 'query', $record_queries );
				try {
					try {
						$doomed_hold_store->selection_transaction(
							static function () use ( $doomed_hold_store, $second, $other, $doomed_hold_id, $expect ): void {
								$locked  = $doomed_hold_store->read( true );
								$cleared = $locked;
								unset( $cleared['native_mutation'] );
								$expect( $doomed_hold_store->compare_and_swap( $locked, $cleared ), 'pinned hold-clear CAS writes uncommitted row' );
								$expect( array_key_exists( 'native_mutation', $second->read() ), 'uncommitted hold clear stays invisible to second connection' );
								$kill_sql = $other->prepare( 'KILL %d', $doomed_hold_id );
								$expect( is_string( $kill_sql ) && false !== $other->query( $kill_sql ), 'control connection kills hold-clear session before COMMIT' );
								$deadline = microtime( true ) + 2.0;
								while ( microtime( true ) < $deadline ) {
									if ( 0 === (int) $other->get_var( $other->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $doomed_hold_id ) ) ) {
										break;
									}
									usleep( 10000 );
								}
								$expect( 0 === (int) $other->get_var( $other->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $doomed_hold_id ) ), 'killed hold-clear session must exit before COMMIT' );
							},
							static function () use ( &$hold_committed ): void {
								$hold_committed = true;
							}
						);
					} catch ( DatabaseLifecycleFailure $failure ) {
						$hold_rejected = $failure->reason();
					}
				} finally {
					remove_filter( 'query', $record_queries );
					$doomed_hold->suppress_errors( $doomed_hold_suppressed );
				}
				$expect( 'selection_commit_uncertain' === $hold_rejected && ! $hold_committed, 'killed hold-clear COMMIT must fail closed without invoking $on_committed' );
				$expect( 'sentinel-before-kill' === wp_cache_get( GitHubSelectionStore::OPTION, 'options' ), 'failed hold-clear ROLLBACK must defer selection cache callbacks' );
				$expect( array_key_exists( 'native_mutation', $second->read() ), 'killed hold-clear session must preserve durable hold in MySQL' );
				foreach ( $filtered_queries as $filtered_sql ) {
					$expect( str_starts_with( $filtered_sql, 'KILL ' ) || str_contains( $filtered_sql, 'information_schema.PROCESSLIST' ) || str_contains( $filtered_sql, 'SHOW ' ) || str_contains( $filtered_sql, 'SELECT option_name, option_value, autoload FROM' ), 'killed hold-clear session must not trigger wpdb reconnect through query filter' );
				}
				$reject(
					static fn () => $doomed_hold_store->selection_transaction( static fn (): null => null ),
					'killed hold-clear session must leave selection store uncertain and refuse reuse'
				);

				$reconnected_hold->options = $table;
				$reconnected_hold_store    = new GitHubSelectionStore( $reconnected_hold, $table );
				$reconnected_hold_context  = new GitHubProviderSelection( $reconnected_hold_store, $plugin_root, $declaration );
				$reconnected_hold_id       = (int) $reconnected_hold->get_var( 'SELECT CONNECTION_ID()' );
				$expect( $reconnected_hold_id > 0, 'reconnected hold-clear wpdb connection has valid MySQL session id' );
				$expect( array() !== $reconnected_hold_store->read(), 'warm reconnected hold-clear store storage check before pinning session' );
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bind wpdb session that reconnects mid-transaction and restore in finally.
				$GLOBALS['wpdb']        = $reconnected_hold;
				$reconnected_suppressed = $reconnected_hold->suppress_errors( true );
				$reconnected_committed  = false;
				$reconnected_rejected   = null;
				try {
					$reconnected_hold_context->release_native_hold(
						$with_hold,
						static function () use ( $reconnected_hold, $other, $reconnected_hold_id, $expect ): void {
							$kill_sql = $other->prepare( 'KILL %d', $reconnected_hold_id );
							$expect( is_string( $kill_sql ) && false !== $other->query( $kill_sql ), 'control connection kills session before verify triggers wpdb reconnect' );
							$deadline = microtime( true ) + 2.0;
							while ( microtime( true ) < $deadline ) {
								if ( 0 === (int) $other->get_var( $other->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $reconnected_hold_id ) ) ) {
									break;
								}
								usleep( 10000 );
							}
							$expect( 0 === (int) $other->get_var( $other->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $reconnected_hold_id ) ), 'killed session must exit before wpdb reconnect probe' );
							$reconnected_hold->get_var( 'SELECT 1' );
							$new_id = (int) $reconnected_hold->get_var( 'SELECT CONNECTION_ID()' );
							$expect( $new_id > 0 && $new_id !== $reconnected_hold_id, 'wpdb must have reconnected onto a different MySQL session' );
						},
						static function () use ( &$reconnected_committed ): void {
							$reconnected_committed = true;
						}
					);
				} catch ( DatabaseLifecycleFailure $failure ) {
					$reconnected_rejected = $failure->reason();
				} finally {
					$reconnected_hold->suppress_errors( $reconnected_suppressed );
				}
				$expect( 'selection_transaction_failed' === $reconnected_rejected && ! $reconnected_committed, 'mid-transaction wpdb reconnect must refuse hold-clear write and $on_committed' );
				$expect( array_key_exists( 'native_mutation', $second->read() ), 'mid-transaction wpdb reconnect must preserve durable hold in MySQL' );
				$reject(
					static fn () => $reconnected_hold_store->selection_transaction( static fn (): null => null ),
					'mid-transaction reconnect must leave selection store uncertain and refuse reuse'
				);

				$database->options = $saved_options;
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore main $database binding before calling Storage::require_recovery_ready() against real Booster tables, then swap back to disposable options table.
				$GLOBALS['wpdb'] = $database;
				$smoke_plugins   = new \RAN\Storage\PluginRepository();
				$smoke_attempts  = new \RAN\Deployment\DeploymentAttemptRepository( $database, selection: $reclaim_context );
				$smoke_plugins->require_recovery_ready();
				$database->options = $table;
				$fresh_token       = (string) time();
				$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
				$query( $database->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'auto_updater.lock', $fresh_token, 'no' ) );
				$expect( array() !== $store->read(), 'warm selection store before hook-free hold verification proof' );
				$filtered_queries->exchangeArray( array() );
				$option_hooks    = 0;
				$record_opt_hook = static function ( mixed $value = null ) use ( &$option_hooks ): mixed {
					++$option_hooks;
					return $value;
				};
				$opt_hook_names  = array( 'pre_option_ran_booster_db_version', 'option_ran_booster_db_version', 'pre_option_auto_updater.lock', 'pre_option_ran_booster_github_selection' );
				add_filter( 'query', $record_queries );
				foreach ( $opt_hook_names as $hook_name ) {
					add_filter( $hook_name, $record_opt_hook );
				}
				try {
					$reclaim_context->release_native_hold(
						$with_hold,
						static function ( callable $execute ) use ( $smoke_plugins, $smoke_attempts, $reclaim_context, $lock, $fresh_token, $expect ): void {
							$expect( null === $smoke_plugins->native_recovery_record( 'held-commit-smoke/held-commit-smoke.php', true, $execute ), 'pinned native_recovery_record observed absence' );
							$smoke_attempts->assert_selection_transition_idle( $reclaim_context, $execute );
							$expect( ! $smoke_attempts->has_unresolved_target_attempt( 'plugin', 'held-commit-smoke/held-commit-smoke.php', true, $execute ), 'pinned has_unresolved_target_attempt observed idle target' );
							$expect( $lock->current_token_sql( $reclaim_context->uses_connection( ... ), $execute ) === $fresh_token, 'pinned current_token_sql verified active updater token' );
						}
					);
				} finally {
					foreach ( $opt_hook_names as $hook_name ) {
						remove_filter( $hook_name, $record_opt_hook );
					}
					remove_filter( 'query', $record_queries );
					$query( $database->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'auto_updater.lock' ) );
				}
				$expect( 0 === $filtered_queries->count(), 'pinned hold verification and CAS must run zero WordPress query filters inside the transaction' );
				$expect( 0 === $option_hooks, 'pinned hold verification and CAS must run zero WordPress option hooks inside the transaction' );
				$expect( ! array_key_exists( 'native_mutation', $second->read() ), 'hook-free pinned hold verification released native_mutation cleanly' );
			} finally {
				$doomed_hold->close();
				$reconnected_hold->close();
				wp_cache_delete( GitHubSelectionStore::OPTION, 'options' );
				$second->selection_transaction(
					static function () use ( $second, $before_hold, $expect ): void {
						$current = $second->read( true );
						if ( array_key_exists( 'native_mutation', $current ) ) {
							$expect( $second->compare_and_swap( $current, $before_hold ), 'clear seeded native_mutation hold after pinned session proofs' );
						}
					}
				);
			}
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

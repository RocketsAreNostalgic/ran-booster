<?php

declare(strict_types=1);

use RAN\Activity\ActivityRequest;
use RAN\Activity\ActivityStore;
use RAN\AdoptionIdentity;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\Storage\Database;

// Exercise optional reporting SQL on the existing installed-archive database matrix.
return static function ( wpdb $database ): void {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		throw new RuntimeException( 'The activity SQL proof requires installed WordPress database configuration.' );
	}
	$container = require __DIR__ . '/core-container-fixture.php';
	if ( ! $container instanceof CoreContainer ) {
		throw new RuntimeException( 'The activity SQL proof requires the installed Core container.' );
	}
	$selection = $container->make( GitHubProviderSelection::class );
	if ( ! $selection->uses_connection( $database ) || null === $selection->expectation() ) {
		throw new RuntimeException( 'The activity SQL proof requires current selection on its database connection.' );
	}
	$table         = $database->prefix . 'ran_activity_smoke_' . bin2hex( random_bytes( 6 ) );
	$journal_table = $table . '_attempts';
	$version       = get_option( ActivityStore::VERSION_OPTION );
	$store         = new ActivityStore( $database, $table );
	$other         = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second        = new ActivityStore( $other, $table );
	$expect        = static function ( bool $condition, string $reason ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed developer-authored proof failure, not rendered HTML.
			throw new RuntimeException( 'Activity SQL proof: ' . $reason );
		}
	};
	$request       = new ActivityRequest( 'release.check', new RepositoryReference( 'ran/activity-proof', 'activity-proof', false, null ), 'plugin', 'activity-proof/plugin.php' );
	$capture       = array(
		'subject_type'     => 'plugin',
		'subject_identity' => 'activity-proof/plugin.php',
		'adoption_token'   => AdoptionIdentity::create( 'gh', 'activity-proof' ),
		'context'          => 'source-1/access-1',
		'purpose'          => 'release.check',
		'provenance'       => 'core',
	);
	$query         = static function ( ?string $sql ) use ( $database ): void {
		if ( null === $sql || false === $database->query( $sql ) ) {
			throw new RuntimeException( 'Activity SQL fixture mutation failed.' );
		}
	};
	try {
		$expect( $store->install(), 'optional schema installation' );
		$engine = $database->get_row( $database->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW TABLE STATUS returns the Engine field.
		$expect( is_object( $engine ) && 'InnoDB' === ( $engine->Engine ?? null ), 'InnoDB storage' );
		$view            = dirname( (string) ( new ReflectionClass( ActivityStore::class ) )->getFileName(), 3 ) . '/views/attempts/index.php';
		$render_activity = static function ( array $activity ) use ( $view ): string {
			$deployment_activity  = $activity;
			$troubleshooting_base = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';
			ob_start();
			require $view;
			return (string) ob_get_clean();
		};
		$package         = wp_json_encode( array( 'gh&+%', 'plugin', 'naïve & + %# "quoted"/plugin.php' ) );
		$expect( is_string( $package ), 'rendered activity package fixture' );
		$activity = array(
			'items'        => array(
				array(
					'state'         => 'succeeded',
					'record_source' => 'activity',
				),
			),
			'filter_state' => array(
				'provider' => 'gh&+%',
				'package'  => $package,
			),
			'next_cursor'  => 'v1|cursor&+%#',
		);
		$rendered = $render_activity( $activity );
		$expect( 1 === preg_match( '/<a class="button" href="([^"]+)">Load older activity<\\/a>/', $rendered, $matches ), 'rendered older activity link' );
		parse_str( (string) wp_parse_url( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $older_query );
		$expect(
			array(
				'page'              => 'ran-booster',
				'tab'               => 'troubleshooting',
				'panel'             => 'activity',
				'activity_provider' => 'gh&+%',
				'activity_package'  => $package,
				'before'            => 'v1|cursor&+%#',
			) === $older_query,
			'older activity link preserves the exact route, filters, and cursor'
		);
		$expect( 1 === preg_match( '/class="ran-booster-package-list-filters__clear" href="([^"]+)"/', $rendered, $matches ), 'rendered clear activity link' );
		parse_str( (string) wp_parse_url( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $clear_query );
		$expect(
			array(
				'page'  => 'ran-booster',
				'tab'   => 'troubleshooting',
				'panel' => 'activity',
			) === $clear_query,
			'clear activity link excludes filters and cursor'
		);
		$activity['items']      = array();
		$activity['has_cursor'] = true;
		unset( $activity['next_cursor'] );
		$rendered = $render_activity( $activity );
		$expect( 1 === preg_match( '/<a class="button" href="([^"]+)">View latest activity<\\/a>/', $rendered, $matches ), 'rendered latest activity link' );
		parse_str( (string) wp_parse_url( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $latest_query );
		$expect(
			array(
				'page'              => 'ran-booster',
				'tab'               => 'troubleshooting',
				'panel'             => 'activity',
				'activity_provider' => 'gh&+%',
				'activity_package'  => $package,
			) === $latest_query,
			'latest activity link preserves filters and removes the cursor'
		);
		$older = $store->begin( 'gh', $request, $capture );
		$newer = $second->begin( 'gh', $request, $capture );
		$expect( null !== $older && null !== $newer, 'two connection admission' );
		if ( null === $older || null === $newer ) {
			return;
		}
		$expect( $second->complete( $newer, 'succeeded', 'no_candidates', '', '', array(), 'established' ), 'newer completion' );
		$expect( $store->complete( $older, 'failed', 'release_list_failed' ), 'late older completion' );
		$expect( $second->complete( $newer, 'succeeded', 'no_candidates', '', '', array(), 'established' ), 'identical completion is idempotent' );
		$expect( ! $store->complete( $newer, 'failed', 'release_list_failed' ) && ! $store->progress( $newer, 'late_stage' ), 'terminal state is immutable' );
		$summary = $store->summary( 'plugin', 'activity-proof/plugin.php', $capture['adoption_token'], $capture['context'], 'release.check' );
		$expect( $summary['available'] && (int) ( $summary['latest']['id'] ?? 0 ) === $newer && (int) ( $summary['observation']['id'] ?? 0 ) === $newer, 'latest follows start order' );
		$replacement = $store->summary( 'plugin', 'activity-proof/plugin.php', AdoptionIdentity::create( 'gh', 'activity-proof' ), $capture['context'], 'release.check' );
		$expect( $replacement['available'] && null === $replacement['latest'], 're-adoption cannot inherit old evidence' );
		$changed = $store->summary( 'plugin', 'activity-proof/plugin.php', $capture['adoption_token'], 'source-2/access-1', 'release.check' );
		$expect( $changed['available'] && null === $changed['observation'], 'source/access currentness' );
		$query( $database->prepare( 'UPDATE %i SET sort_at = %s', $table, '2026-01-01 12:00:00' ) );
		$page = $store->activity_history(
			array(
				'provider'         => 'gh',
				'subject_identity' => 'activity-proof/plugin.php',
			),
			null,
			1
		);
		$expect( $page['available'] && (int) ( $page['records'][0]['id'] ?? 0 ) === $newer, 'timestamp ties page by stable ID' );
		$next = $store->activity_history(
			array(
				'provider'         => 'gh',
				'subject_identity' => 'activity-proof/plugin.php',
			),
			array(
				'time'   => '2026-01-01 12:00:00',
				'source' => 'activity',
				'id'     => $newer,
			),
			1
		);
		$expect( $next['available'] && (int) ( $next['records'][0]['id'] ?? 0 ) === $older, 'cursor neither skips nor repeats' );
		$filtered = $store->activity_history( array( 'subject_identity' => 'another/plugin.php' ) );
		$expect( $filtered['available'] && array() === $filtered['records'], 'package filter applies before paging' );

		// The incomplete older operation gains an observation between selection and deletion.
		$ordinary            = $capture;
		$ordinary['purpose'] = null;
		$discard             = $store->begin( 'gh', $request, $ordinary );
		$expect( null !== $discard, 'ordinary pruning race candidate' );
		$late   = $store->begin( 'gh', $request, $capture );
		$latest = $store->begin( 'gh', $request, $capture );
		$expect( null !== $late && null !== $latest, 'pruning race fixture admission' );
		if ( null === $late || null === $latest ) {
			return;
		}
		$query( $database->prepare( 'UPDATE %i SET started_at = %s', $table, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
		$raced = false;
		$race  = static function ( string $sql ) use ( $table, $late, $second, &$raced, $expect ): string {
			if ( ! $raced && str_starts_with( $sql, 'DELETE FROM ' . $table ) && str_contains( $sql, 'id = ' . $late . ' ' ) ) {
				$raced = true;
				$expect( $second->complete( $late, 'succeeded', 'no_candidates', '', '', array(), 'established' ), 'concurrent establishment' );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$expect( false !== $store->prune( array( $capture ) ), 'bounded prune SQL' );
		} finally {
			remove_filter( 'query', $race );
		}
		$expect( $raced, 'completion must interleave before actual deletion' );
		$expect( null === $database->get_var( $database->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $discard ) ), 'the same batch deletes an unchanged candidate' );
		$summary = $store->summary( 'plugin', 'activity-proof/plugin.php', $capture['adoption_token'], $capture['context'], 'release.check' );
		$expect( (int) ( $summary['latest']['id'] ?? 0 ) === $latest && (int) ( $summary['observation']['id'] ?? 0 ) === $late, 'pruning preserves current attempt and concurrent observation' );
		$expect( array() === $store->activity_history()['records'], 'aged protected observations stay out of ordinary history' );
		$ordinary            = $capture;
		$ordinary['purpose'] = null;
		for ( $index = 0; $index < 2010; ++$index ) {
			$expect( null !== $store->begin( 'gh', $request, $ordinary ), 'retention fixture insertion' );
		}
		$expect( false !== $store->prune( array( $capture ) ), 'count retention pruning' );
		$count = (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$expect( $count <= 2002, 'ordinary row target plus two current pins' );
		$summary = $store->summary( 'plugin', 'activity-proof/plugin.php', $capture['adoption_token'], $capture['context'], 'release.check' );
		$expect( (int) ( $summary['latest']['id'] ?? 0 ) === $latest && (int) ( $summary['observation']['id'] ?? 0 ) === $late, 'ordinary writes do not evict protected summaries' );
		$expect( false !== $store->prune( array() ), 'departed context pruning' );
		$expect( null === $store->summary( 'plugin', 'activity-proof/plugin.php', $capture['adoption_token'], $capture['context'], 'release.check' )['latest'], 'departed pins lose protection' );

		// Filter choices use the same age/capacity scope as history, except unresolved deployments remain visible.
		$query( $database->prepare( 'DELETE FROM %i', $table ) );
		$deployment_correlation = str_repeat( 'd', 32 );
		$deployment             = $store->begin( 'gh', $request, $capture, $deployment_correlation );
		$outside                = $store->begin( 'gh', $request, $capture );
		$expect( null !== $deployment && null !== $outside, 'filter choice aged fixture admission' );
		if ( null === $deployment || null === $outside ) {
			return;
		}
		$expect(
			$store->progress_by_correlation(
				$deployment_correlation,
				'deployment.running',
				facts: array(
					'deployment_attempt_id' => 1,
					'deployment_source'     => 'manual',
					'deployment_state'      => 'running',
				)
			),
			'unresolved deployment fixture'
		);
		$query( $database->prepare( 'UPDATE %i SET provider = %s, subject_identity = %s, started_at = %s WHERE id = %d', $table, 'deploy', 'protected/plugin.php', '2026-01-01 00:00:00', $deployment ) );
		$query( $database->prepare( 'UPDATE %i SET provider = %s, subject_identity = %s, started_at = %s WHERE id = %d', $table, 'obsolete', 'outside/plugin.php', '2026-01-01 00:00:00', $outside ) );
		$digits  = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
		$numbers = "(SELECT a.n + 10*b.n + 100*c.n + 1000*d.n AS n FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c CROSS JOIN $digits d)";
		$query(
			$database->prepare(
				"INSERT INTO %i (correlation,sort_at,started_at,provider,subject_type,subject_identity,context,context_key,operation,provenance,actor,status,message,remediation,facts,effect) SELECT CONCAT('a',LPAD(HEX(n+1),31,'0')),NOW(),NOW(),'bulk','plugin','bulk/plugin.php','fixture',REPEAT('b',64),'release.check','core',1,'succeeded','','','{}','none' FROM $numbers numbers WHERE n < 2000",
				$table
			)
		);
		foreach ( array(
			array( 'Gh', 'plugin', 'Case/plugin.php' ),
			array( 'bb', 'plugin', 'Case/plugin.php' ),
			array( 'gh', 'plugin', 'Case/plugin.php' ),
			array( 'gh', 'plugin', 'case/plugin.php' ),
			array( 'gh', 'theme', 'case/plugin.php' ),
			array( 'api', 'repository', 'owner/repository' ),
		) as list( $provider, $type, $identity ) ) {
			$id = $store->begin( 'gh', $request, $capture );
			$expect( null !== $id, 'filter choice collision fixture admission' );
			if ( null === $id ) {
				return;
			}
			$query( $database->prepare( 'UPDATE %i SET provider = %s, subject_type = %s, subject_identity = %s WHERE id = %d', $table, $provider, $type, $identity, $id ) );
		}
		$exact_collision = $store->activity_history(
			array(
				'provider'         => 'gh',
				'subject_type'     => 'plugin',
				'subject_identity' => 'Case/plugin.php',
			)
		);
		$expect( $exact_collision['available'] && 1 === count( $exact_collision['records'] ), 'history filters retain exact provider and identity case' );
		$history = $store->activity_history();
		$expect( $history['available'] && ! in_array( $deployment, array_map( static fn ( array $record ): int => (int) $record['id'], $history['records'] ), true ), 'aged protected deployment remains outside the ordinary history page' );
		$expect( false !== $store->prune( array() ), 'age and capacity pruning removes ordinary reports' );
		$expect( null === $database->get_var( $database->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $outside ) ), 'ordinary aged report is pruned' );
		$filter_choices = $store->activity_filter_choices();
		$expect( $filter_choices['available'], 'filter choices available after retention pruning' );
		$expect( in_array( 'api', $filter_choices['providers'], true ) && in_array( 'deploy', $filter_choices['providers'], true ) && ! in_array( 'obsolete', $filter_choices['providers'], true ), 'providers follow retained history and include nonpackages' );
		$packages = array_map(
			static fn ( array $package ): string => implode( '|', $package ),
			array_values( array_filter( $filter_choices['packages'], static fn ( array $package ): bool => in_array( $package['provider'], array( 'Gh', 'bb', 'deploy', 'gh' ), true ) ) )
		);
		$expect(
			array(
				'Gh|plugin|Case/plugin.php',
				'bb|plugin|Case/plugin.php',
				'deploy|plugin|protected/plugin.php',
				'gh|plugin|Case/plugin.php',
				'gh|plugin|case/plugin.php',
				'gh|theme|case/plugin.php',
			) === $packages,
			'package choices retain exact provider type and case while excluding nonpackages'
		);
		$query( $database->prepare( 'DELETE FROM %i', $table ) );

		// The live table definition must remain stable until a coupled transaction ends.
		$raced = false;
		$race  = static function ( string $sql ) use ( $table, $other, &$raced, $expect ): string {
			if ( $raced || ! str_contains( $sql, 'information_schema.TABLES' ) || ! str_contains( $sql, $table ) ) {
				return $sql;
			}
			$raced  = true;
			$errors = $other->suppress_errors( true );
			try {
				$expect( false !== $other->query( 'SET SESSION lock_wait_timeout = 1' ), 'metadata race timeout' );
				$conversion = $other->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table );
				$expect( null !== $conversion && false === $other->query( $conversion ), 'engine conversion blocked by active transaction' );
				$drop_index = $other->prepare( 'ALTER TABLE %i DROP INDEX correlation', $table );
				$expect( null !== $drop_index && false === $other->query( $drop_index ), 'identity index removal blocked by active transaction' );
			} finally {
				$other->suppress_errors( $errors );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		$query( 'START TRANSACTION' );
		try {
			$expect( null !== $store->begin( 'gh', $request, $capture, str_repeat( 'c', 32 ) ), 'coupled InnoDB admission' );
			$expect( $raced, 'engine conversion interleaves before verification and insert' );
		} finally {
			remove_filter( 'query', $race );
			$query( 'ROLLBACK' );
		}

		$query( $database->prepare( 'CREATE TABLE %i LIKE %i', $journal_table, Database::attempt_table_name() ) );
		$journal = new DeploymentAttemptRepository( $database, $journal_table, activity: $store, selection: $selection );
		$deploy  = new DeploymentRequest( 'ran/activity-proof', null, false, 'main', 'activity-proof', null, DeploymentPolicy::MANUAL, 1 );
		$before  = (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) );
		$expect( ! $store->install(), 'installation rejects an existing nontransactional table' );
		try {
			$journal->admit_and_claim_manual( 'install', 'plugin', 'gh', 'activity-proof', $deploy, 'main', 'branch', 1 );
			throw new RuntimeException( 'A nontransactional Activity table admitted a deployment.' );
		} catch ( DeploymentStorageFailure ) {
			$expect( 0 === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $journal_table ) ), 'rejected admission rolls back journal' );
			$expect( $before === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'rejected admission leaves no orphan report' );
		}
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		foreach ( array( '', ', ADD KEY correlation (correlation)', ', ADD UNIQUE KEY correlation (correlation,id)', ', ADD UNIQUE KEY correlation (correlation(31))' ) as $replacement ) {
			$query( $database->prepare( 'ALTER TABLE %i DROP INDEX correlation', $table ) . $replacement );
			$expect( ! $store->install(), 'installation rejects missing or weakened unique correlation' );
			try {
				$journal->admit_and_claim_manual( 'install', 'plugin', 'gh', 'activity-proof', $deploy, 'main', 'branch', 1 );
				throw new RuntimeException( 'An incompatible identity index admitted a deployment.' );
			} catch ( DeploymentStorageFailure ) {
				$expect( 0 === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $journal_table ) ), 'incompatible index rolls back journal' );
				$expect( $before === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'incompatible index leaves no orphan report' );
			}
			if ( '' !== $replacement ) {
				$query( $database->prepare( 'ALTER TABLE %i DROP INDEX correlation', $table ) );
			}
			$query( $database->prepare( 'ALTER TABLE %i ADD UNIQUE KEY correlation (correlation)', $table ) );
		}
		$expect( $store->install(), 'compatible existing schema verifies after restoration' );
		// A changed initial fence or rejected Activity progress must roll back both stores.
		foreach ( array( 'fence', 'progress', 'commit' ) as $failure ) {
			$injected = false;
			$inject   = static function ( string $sql ) use ( $database, $journal_table, $table, $failure, &$injected, $query ): string {
				if ( ! $injected && 'fence' === $failure && str_starts_with( $sql, "SELECT * FROM `$journal_table` WHERE correlation_id = " ) ) {
					$injected = true;
					$query( $database->prepare( 'UPDATE %i SET mutation_started_at = %s', $journal_table, '2026-07-19 00:00:01' ) );
				}
				if ( ! $injected && 'progress' === $failure && str_starts_with( $sql, "UPDATE $table SET stage = " ) ) {
					$injected = true;
					return $sql . ' AND 1 = 0';
				}
				if ( ! $injected && 'commit' === $failure && 'COMMIT' === $sql ) {
					$injected = true;
					return 'INVALID MANUAL ADMISSION COMMIT';
				}
				return $sql;
			};
			$errors   = $database->suppress_errors( true );
			add_filter( 'query', $inject );
			try {
				$journal->admit_and_claim_manual( 'install', 'plugin', 'gh', 'activity-proof', $deploy, 'main', 'branch', 1 );
				throw new RuntimeException( 'Corrupt admission or failed coupled reporting committed.' );
			} catch ( DeploymentStorageFailure $caught ) {
				$expect( $injected, 'manual admission failure injection reached its boundary' );
				if ( 'fence' === $failure ) {
					$expect( DeploymentStorageFailure::inconsistent()->getMessage() === $caught->getMessage(), 'changed fence fails exact row verification' );
				}
			} finally {
				remove_filter( 'query', $inject );
				$database->suppress_errors( $errors );
			}
			$expect( 0 === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $journal_table ) ), 'failed immediate admission rolls back journal' );
			$expect( $before === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'failed immediate admission rolls back Activity' );
		}

		$admission_sql = array();
		$observe       = static function ( string $sql ) use ( &$admission_sql ): string {
			$admission_sql[] = $sql;
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$attempt = $journal->admit_and_claim_manual( 'install', 'plugin', 'gh', 'activity-proof', $deploy, 'main', 'branch', 1 );
		} finally {
			remove_filter( 'query', $observe );
		}
		$expect( 1 === count( array_filter( $admission_sql, static fn ( string $sql ): bool => str_starts_with( $sql, "SELECT * FROM `$journal_table` WHERE correlation_id = " ) ) ), 'immediate admission reads its complete inserted row once' );
		$expect( array() === array_filter( $admission_sql, static fn ( string $sql ): bool => str_starts_with( $sql, "UPDATE `$journal_table`" ) ), 'immediate admission needs no queued claim update' );
		$expect( DeploymentState::RUNNING === $attempt->get_state() && null === $attempt->safe_data()['mutation_started_at'], 'immediate admission starts running before mutation' );
		$report = $database->get_row( $database->prepare( 'SELECT * FROM %i WHERE correlation = %s', $table, $attempt->get_correlation_id() ), ARRAY_A );
		$expect( is_array( $report ) && 'incomplete' === $report['status'] && 'deployment.running' === $report['stage'], 'manual Activity starts at the running stage' );
		$facts = is_array( $report ) && is_string( $report['facts'] ) ? json_decode( $report['facts'], true ) : null;
		$expect(
			array(
				'deployment_attempt_id'          => $attempt->get_id(),
				'deployment_finished_at'         => null,
				'deployment_mutation_started_at' => null,
				'deployment_outcome'             => null,
				'deployment_package_slug'        => 'activity-proof',
				'deployment_requested_ref'       => 'main',
				'deployment_resolved_at'         => null,
				'deployment_resolved_by'         => null,
				'deployment_resolved_ref'        => null,
				'deployment_source'              => 'manual',
				'deployment_state'               => 'running',
			) === $facts,
			'manual Activity carries the complete admitted facts'
		);
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) );
		foreach ( array(
			static fn () => $journal->mark_mutation_started( $attempt->get_id() ),
			static fn () => $journal->finish( $attempt->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED ) ),
		) as $operation ) {
			try {
				$operation();
				throw new RuntimeException( 'A nontransactional Activity table changed a deployment.' );
			} catch ( DeploymentStorageFailure ) {
				$expect( DeploymentState::RUNNING === $journal->find_exact( $attempt->get_id() )?->get_state(), 'rejected transition rolls back journal' );
			}
		}
		$expect( 'incomplete' === $database->get_var( $database->prepare( 'SELECT status FROM %i WHERE correlation = %s', $table, $attempt->get_correlation_id() ) ), 'rejected transitions cannot complete the report' );
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		$journal->finish( $attempt->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED ) );
		$expect( 'succeeded' === $database->get_var( $database->prepare( 'SELECT status FROM %i WHERE correlation = %s', $table, $attempt->get_correlation_id() ) ), 'restored InnoDB permits atomic completion' );
		// Fixed fixture measurements, not live-site latency guarantees or performance gates.
		$digits  = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
		$numbers = "(SELECT a.n + 10*b.n + 100*c.n + 1000*d.n AS n FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c CROSS JOIN $digits d)";
		foreach ( array( 2000, 10000 ) as $size ) {
			$query( $database->prepare( 'DELETE FROM %i', $table ) );
			$query( $database->prepare( 'DELETE FROM %i', $journal_table ) );
			// Ten equally represented packages; oldest half is past the ordinary age target.
			$query(
				$database->prepare(
					"INSERT INTO %i (correlation,sort_at,started_at,completed_at,provider,subject_type,subject_identity,adoption_token,context,context_key,operation,purpose,provenance,actor,status,message,remediation,facts,effect)
		        SELECT LPAD(HEX(n+1),32,'0'), NOW(), IF(n < %d,NOW()-INTERVAL 40 DAY,NOW()),NOW(),'gh','plugin',CONCAT('proof-',MOD(n,10),'/plugin.php'),%s,'source-1/access-1',SHA2(CONCAT('plugin',CHAR(0),CONCAT('proof-',MOD(n,10),'/plugin.php'),CHAR(0),%s,CHAR(0),'source-1/access-1'),256),'release.check','release.check','core',1,'succeeded','','','{}',IF(MOD(n,3)=0,'established','none') FROM $numbers numbers WHERE n < %d",
					$table,
					intdiv( $size, 2 ),
					$capture['adoption_token'],
					$capture['adoption_token'],
					$size
				)
			);
			$request_json = wp_json_encode( array( 'adoption_token' => $capture['adoption_token'] ) );
			$expect( is_string( $request_json ), 'measurement JSON' );
			$query(
				$database->prepare(
					"INSERT INTO %i (correlation_id,source,operation,package_type,package_slug,package_source,package_source_revision,provider,provider_repository_id,requested_ref,state,request_json,created_at,finished_at)
		        SELECT LPAD(HEX(n+1),32,'0'),'manual','update','plugin',CONCAT('proof-',MOD(n,10)),'branch',1,'gh','activity-proof','main',IF(MOD(n,3)=0,'succeeded','failed'),%s,NOW(),NOW() FROM $numbers numbers WHERE n < %d",
					$journal_table,
					$request_json,
					$size
				)
			);
			$measure = static function ( string $label, callable $operation ) use ( $database, $size ): void {
				$before = $database->num_queries;
				$start  = hrtime( true );
				$operation();
				$milliseconds = ( hrtime( true ) - $start ) / 1000000;
				// Fixed diagnostic output; no threshold and no live-site performance claim.
				echo 'Activity storage measurement ' . wp_json_encode(
					array(
						'rows'         => $size,
						'operation'    => $label,
						'queries'      => $database->num_queries - $before,
						'milliseconds' => round( $milliseconds, 3 ),
					)
				) . "\n";
			};
			$measure(
				'filtered_history',
				static function () use ( $store, $expect ): void {
					$expect(
						$store->activity_history(
							array(
								'subject_identity' => 'proof-0/plugin.php',
								'outcome'          => 'succeeded',
							)
						)['available'],
						'measurement history available'
					);
				}
			);
			$contexts = array();
			for ( $package = 0; $package < 10; ++$package ) {
				$context                     = $capture;
				$context['subject_identity'] = 'proof-' . $package . '/plugin.php';
				$contexts[]                  = $context;
			}
			$measure(
				'ten_activity_summaries',
				static function () use ( $store, $contexts, $expect ): void {
					foreach ( $contexts as $context ) {
						$expect( $store->summary( 'plugin', $context['subject_identity'], $context['adoption_token'], $context['context'], 'release.check' )['available'], 'measurement summary available' );
					}
				}
			);
			$measure(
				'journal_locking_count',
				static function () use ( $database, $journal_table, $query, $expect, $size ): void {
					$query( 'START TRANSACTION' );
					try {
						$expect( $size === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i FOR UPDATE', $journal_table ) ), 'measurement journal count' );
					} finally {
						$query( 'ROLLBACK' );
					}
				}
			);
			$measure(
				'ten_journal_adoption_summaries_sql',
				static function () use ( $database, $journal_table, $capture, $expect ): void {
					for ( $package = 0; $package < 10; ++$package ) {
						$fragment = '"adoption_token":"' . $capture['adoption_token'] . '"';
						$sql      = $database->prepare( "SELECT * FROM %i AS attempts WHERE attempts.id = (SELECT MAX(latest.id) FROM %i AS latest WHERE latest.package_type = 'plugin' AND latest.package_slug = %s AND LOCATE(BINARY %s, BINARY latest.request_json) > 0) OR attempts.id = (SELECT MAX(success.id) FROM %i AS success WHERE success.package_type = 'plugin' AND success.package_slug = %s AND success.state = 'succeeded' AND LOCATE(BINARY %s, BINARY success.request_json) > 0) ORDER BY attempts.id DESC LIMIT 2", $journal_table, $journal_table, 'proof-' . $package, $fragment, $journal_table, 'proof-' . $package, $fragment );
						$rows     = $database->get_results( $sql );
						$expect( is_array( $rows ) && count( $rows ) > 0 && '' === $database->last_error, 'measurement journal summary SQL' );
					}
				}
			);
			$measure(
				'one_retention_batch',
				static function () use ( $store, $contexts, $expect ): void {
					$expect( 100 === $store->prune( $contexts ), 'measurement bounded retention batch' );
				}
			);
		}
	} finally {
		$query( $database->prepare( 'DROP TABLE IF EXISTS %i', $journal_table ) );
		$query( $database->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		if ( false === $version ) {
			delete_option( ActivityStore::VERSION_OPTION );
		} else {
			update_option( ActivityStore::VERSION_OPTION, $version, false );
		}
		$other->close();
	}
};

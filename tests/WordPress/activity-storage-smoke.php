<?php

declare(strict_types=1);

use RAN\Activity\ActivityRequest;
use RAN\Activity\ActivityStore;
use RAN\AdoptionIdentity;
use RAN\RepositoryProvider\RepositoryReference;

// Exercise optional reporting SQL on the existing installed-archive database matrix.
return static function ( wpdb $database ): void {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		throw new RuntimeException( 'The activity SQL proof requires installed WordPress database configuration.' );
	}
	$table   = $database->prefix . 'ran_activity_smoke_' . bin2hex( random_bytes( 6 ) );
	$version = get_option( ActivityStore::VERSION_OPTION );
	$store   = new ActivityStore( $database, $table );
	$other   = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second  = new ActivityStore( $other, $table );
	$expect  = static function ( bool $condition, string $reason ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed developer-authored proof failure, not rendered HTML.
			throw new RuntimeException( 'Activity SQL proof: ' . $reason );
		}
	};
	$request = new ActivityRequest( 'release.check', new RepositoryReference( 'ran/activity-proof', 'activity-proof', false, null ), 'plugin', 'activity-proof/plugin.php' );
	$capture = array(
		'subject_type'     => 'plugin',
		'subject_identity' => 'activity-proof/plugin.php',
		'adoption_token'   => AdoptionIdentity::create( 'gh', 'activity-proof' ),
		'context'          => 'source-1/access-1',
		'purpose'          => 'release.check',
		'provenance'       => 'core',
	);
	$query   = static function ( ?string $sql ) use ( $database ): void {
		if ( null === $sql || false === $database->query( $sql ) ) {
			throw new RuntimeException( 'Activity SQL fixture mutation failed.' );
		}
	};
	try {
		$expect( $store->install(), 'optional schema installation' );
		$engine = $database->get_row( $database->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW TABLE STATUS returns the Engine field.
		$expect( is_object( $engine ) && 'InnoDB' === ( $engine->Engine ?? null ), 'InnoDB storage' );
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
		$page = $store->history(
			array(
				'provider'         => 'gh',
				'subject_identity' => 'activity-proof/plugin.php',
			),
			null,
			1
		);
		$expect( $page['available'] && null !== $page['next_cursor'] && (int) ( $page['records'][0]['id'] ?? 0 ) === $newer, 'timestamp ties page by stable ID' );
		$next = $store->history(
			array(
				'provider'         => 'gh',
				'subject_identity' => 'activity-proof/plugin.php',
			),
			$page['next_cursor'],
			1
		);
		$expect( $next['available'] && (int) ( $next['records'][0]['id'] ?? 0 ) === $older && null === $next['next_cursor'], 'cursor neither skips nor repeats' );
		$filtered = $store->activity_history( array( 'subject_identity' => 'another/plugin.php' ) );
		$expect( $filtered['available'] && array() === $filtered['records'], 'package filter applies before paging' );

		// The incomplete older operation gains an observation between selection and deletion.
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
		$summary = $store->summary( 'plugin', 'activity-proof/plugin.php', $capture['adoption_token'], $capture['context'], 'release.check' );
		$expect( (int) ( $summary['latest']['id'] ?? 0 ) === $latest && (int) ( $summary['observation']['id'] ?? 0 ) === $late, 'pruning preserves current attempt and concurrent observation' );
		$expect( array() === $store->history()['records'], 'aged protected observations stay out of ordinary history' );
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
	} finally {
		$query( $database->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		if ( false === $version ) {
			delete_option( ActivityStore::VERSION_OPTION );
		} else {
			update_option( ActivityStore::VERSION_OPTION, $version, false );
		}
		$other->close();
	}
};

<?php

/** @var list<string> $args */
$ran_booster_race_args = $args;
final class RAN_Booster_General_Settings_Race {
	private string $action;
	private string $run;
	private string $state;
	private string $ready;
	private string $release;
	private string $result;
	private string $order;
	private string $option;
	private string $table;
	private string $slug;
	private string $identifier;
	private string $directory;
	/** @param list<string> $args */
	public function __construct( array $args ) {
		[$this->action, $this->run, $this->state, $this->ready, $this->release, $this->result] = array_pad( $args, 6, '' );
		$this->order      = (string) getenv( 'RAN_BOOSTER_GENERAL_RACE_ORDER' );
		$this->option     = \RAN\Settings\GeneralPreferenceStore::OPTION_NAME;
		$this->table      = ran_booster_table_name();
		$this->slug       = 'ran-general-race-' . $this->run;
		$this->identifier = $this->slug . '/entry.php';
		$this->directory  = WP_PLUGIN_DIR . '/' . $this->slug;
	}
	public function run(): void {
		$this->assert_arguments();
		$this->assert_disposable_site();
		match ( $this->action ) {
			'setup' => $this->setup(), 'cleanup' => $this->cleanup(), 'assert' => $this->assert_result(), 'save' => $this->save(), 'adopt' => $this->adopt() };
	}
	private function assert_arguments(): void {
		if ( ! in_array( $this->action, array( 'setup', 'save', 'adopt', 'assert', 'cleanup' ), true ) || 1 !== preg_match( '/\A[a-f0-9]{24}\z/D', $this->run ) || ! in_array( $this->state, array( 'absent', 'false', 'true' ), true ) || ! in_array( $this->order, array( 'saved-first', 'managed-first' ), true ) ) {
			throw new RuntimeException( 'Invalid General Settings race arguments.' ); }
		foreach ( array( $this->ready, $this->release, $this->result ) as $path ) {
			if ( '' !== $path && ( ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) || is_link( $path ) ) ) {
				throw new RuntimeException( 'Race barriers must be owned temporary paths.' ); }
		}
	}
	private function assert_disposable_site(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local marker proves the disposable test fixture.
		$marker = file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' );
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) || ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'WP_PLUGIN_DIR' ) || realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH ) . '/wp-content' || realpath( WP_PLUGIN_DIR ) !== realpath( WP_CONTENT_DIR ) . '/plugins' || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR ) || is_link( ABSPATH . '.ran-booster-disposable-test-site' ) || 'RAN Booster disposable test site' !== trim( (string) $marker ) ) {
			throw new RuntimeException( 'The exact disposable WordPress marker and paths are required.' ); }
	}
	private function setup(): void {
		if ( file_exists( $this->directory ) || is_link( $this->directory ) || ! wp_mkdir_p( $this->directory ) ) {
			throw new RuntimeException( 'Disposable race plugin path is unavailable.' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creates the disposable plugin fixture.
		file_put_contents( $this->directory . '/entry.php', "<?php\n/* Plugin Name: General race\nVersion: 1.0.0\n*/\n" );
		global $wpdb;
		if ( 0 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider = %s', $this->table, 'gh' ) ) ) {
			throw new RuntimeException( 'The disposable fixture has pre-existing GitHub management rows.' ); }
		$wpdb->delete(
			$this->table,
			array(
				'package'      => $this->identifier,
				'package_type' => 1,
			)
		);
		delete_option( $this->option );
		if ( 'absent' !== $this->state ) {
			add_option(
				$this->option,
				array(
					'hide_github'  => 'true' === $this->state,
					'history_days' => 17,
				),
				'',
				false
			); }
	}
	private function cleanup(): void {
		global $wpdb;
		$wpdb->delete(
			$this->table,
			array(
				'package'      => $this->identifier,
				'package_type' => 1,
			)
		);
		delete_option( $this->option );
		if ( realpath( $this->directory ) === $this->directory ) {
			wp_delete_file( $this->directory . '/entry.php' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the owned empty fixture directory.
			rmdir( $this->directory ); }
	}
	private function assert_result(): void {
		global $wpdb;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads owned child-process result file.
		$adopt = json_decode( (string) file_get_contents( $this->result . '.adopt' ), true, 8, JSON_THROW_ON_ERROR );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads owned child-process result file.
		$save    = json_decode( (string) file_get_contents( $this->result . '.save' ), true, 8, JSON_THROW_ON_ERROR );
		$entry   = array_merge( $adopt, $save );
		$value   = get_option( $this->option, null );
		$rows    = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE package_type = %d AND package = %s AND provider = %s', $this->table, 1, $this->identifier, 'gh' ) );
		$invalid = ! is_array( $entry ) || 1 !== (int) $rows || true !== ( $entry['adopted'] ?? null ) || ( 'saved-first' === ( $entry['order'] ?? null ) && ! in_array( $entry['save'] ?? null, array( 'saved', 'unchanged' ), true ) ) || ( 'managed-first' === ( $entry['order'] ?? null ) && 'stale' !== ( $entry['save'] ?? null ) ) || ( is_array( $value ) && true === ( $value['hide_github'] ?? null ) );
		if ( $invalid ) {
			throw new RuntimeException(
				'General Save survived or reintroduced GitHub hiding: ' . wp_json_encode(
					array(
						'state'  => $this->state,
						'entry'  => $entry,
						'rows'   => (int) $rows,
						'option' => $value,
					)
				)
			); }
		if ( 'absent' === $this->state && 'managed-first' === ( $entry['order'] ?? null ) && null !== $value ) {
			throw new RuntimeException( 'Confirmed management seeded an absent preference.' ); }
		if ( is_array( $value ) && 17 !== ( $value['history_days'] ?? null ) && 'absent' !== $this->state ) {
			throw new RuntimeException( 'Visibility lifecycle changed an unrelated preference.' ); }
	}
	private function wait_at_barrier(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic owned-file barrier across WP-CLI processes.
		if ( false === fopen( $this->ready, 'x' ) ) {
			throw new RuntimeException( 'Race barrier already exists.' ); }
		$until = microtime( true ) + 20;
		while ( ! file_exists( $this->release ) ) {
			if ( microtime( true ) > $until ) {
				throw new RuntimeException( 'Race barrier timed out.' );
			} usleep( 20000 ); }
	}
	private function save(): void {
		$store   = new \RAN\Settings\GeneralPreferenceStore();
		$token   = $store->snapshot()['token'];
		$lock    = array();
		$outcome = $store->save(
			$token,
			array(
				'hide_github'           => '1',
				'self_update_discovery' => '1',
				'history_rows'          => '2000',
				'history_days'          => '17',
				'max_archive_bytes'     => '52428800',
			),
			function ( callable $operation ) use ( &$lock ): string {
				global $wpdb;
				$query = $wpdb->prepare( 'SELECT id FROM %i WHERE provider = %s FOR UPDATE', ran_booster_table_name(), 'gh' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared provider-range query.
				$rows = $wpdb->get_results( $query );
				$lock = array(
					'error' => $wpdb->last_error,
					'rows'  => is_array( $rows ) ? count( $rows ) : null,
				);
				if ( '' !== $lock['error'] || ! is_array( $rows ) ) {
					throw new RuntimeException( 'GitHub range lock was unavailable.' );
				} if ( '' !== $this->ready ) {
					$this->wait_at_barrier();
				} return $operation();
			},
			function (): bool {
				global $wpdb;
				return 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider = %s', ran_booster_table_name(), 'gh' ) );
			}
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes owned child-process result file.
		file_put_contents(
			$this->result . '.save',
			wp_json_encode(
				array(
					'save'  => $outcome,
					'order' => $this->order,
					'lock'  => $lock,
				)
			)
		);
	}
	private function adopt(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$filter = '' === $this->ready ? null : function ( string $query ): string {
			if ( 'COMMIT' === $query ) {
				$this->wait_at_barrier();
			} return $query;
		};
		if ( null !== $filter ) {
			add_filter( 'query', $filter ); }
		try {
			$package = ( new \RAN\Storage\PluginRepository() )->installed_plugin_from_file( $this->identifier );
			$package->set_repository( new \RAN\ManagedRepository( 'gh', 'example/' . $this->slug, 'race-' . $this->run, 'main' ) );
			$adopted = ( new \RAN\Storage\PluginRepository() )->adopt( $package )->is_successful();
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'query', $filter ); }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes owned child-process result file.
		file_put_contents(
			$this->result . '.adopt',
			wp_json_encode(
				array(
					'adopted' => $adopted,
					'order'   => $this->order,
				)
			)
		);
	}
}
( new RAN_Booster_General_Settings_Race( $ran_booster_race_args ) )->run();

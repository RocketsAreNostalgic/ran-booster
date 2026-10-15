<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use RAN\Storage\SchemaConnection;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

/** Actual host composition uses canonical selection SQL fixtures and a passive bundled main file. */
final class HostSelectionDatabase extends SchemaConnection {
	public string $prefix  = 'wp_';
	public string $options = 'wp_options';
	public string $basename;
	private string $main_file;
	private SelectionContextDatabase $authority;
	public function __construct() {
		$this->authority = new SelectionContextDatabase();
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Actual WordPress-owned constant for isolated host composition.
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ran-host-composition-plugins' );
		}
		if ( ! is_dir( WP_PLUGIN_DIR ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test-owned passive plugin root.
			mkdir( WP_PLUGIN_DIR, 0700, true );
		}
		$this->basename  = 'ran-host-composition-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$this->main_file = WP_PLUGIN_DIR . '/' . $this->basename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact test fixture main file, never executable plugin code.
		file_put_contents( $this->main_file, '<?php /* Host composition fixture */' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact WordPress active-plugin wire fixture.
		$this->authority->active_bytes = serialize( array( $this->basename ) );
		$declaration                   = new \RAN\RepositoryProvider\GitHubReplacementDeclaration( array_merge( array( 'plugin_basename' => $this->basename ), \RAN\BoosterGitHubProvider\V1\GitHubProvider::compatibility_contract() ) );
		( new \RAN\Storage\GitHubSelectionStore( $this, $this->options ) )->initialize_bundled( $declaration );
	}
	public function __destruct() {
		if ( is_file( $this->main_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the dedicated passive fixture main file.
			unlink( $this->main_file );
		}
	}
	public function query( string $query ): int|bool {
		return $this->authority->query( $query );
	}
	public function get_charset_collate(): string {
		return '';
	}
	public function esc_like( string $value ): string {
		return $value;
	}
	public function prepare( string $query, mixed ...$arguments ): string {
		return $this->authority->prepare( $query, ...$arguments );
	}
	public function get_var( string $query ): mixed {
		throw new \LogicException( $query );
	}
	public function get_row( string $query ): object {
		return $this->authority->get_row( $query );
	}
	/** @return list<object> */
	public function get_results( string $query ): array {
		return $this->authority->get_results( $query );
	}
}

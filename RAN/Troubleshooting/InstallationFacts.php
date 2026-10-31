<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use Closure;
use RAN\Admin\AdminAddOnRegistry;
use RAN\RepositoryProvider\ProviderRegistry;

/** Fixed local observations, shared by Installation and the support capture. */
final class InstallationFacts {
	/** @var array{state:string,admitted:bool,selected:string,version:string,provider_api:int,compatibility_revision:string,identity:?array{provider_code:string,selection_generation:int,implementation_fingerprint:string}}|null */
	private ?array $github_observed = null;

	/**
	 * @param Closure(): array{state:string,admitted:bool,selected:string,version:string,provider_api:int,compatibility_revision:string,identity:?array{provider_code:string,selection_generation:int,implementation_fingerprint:string}}|null $github_observation
	 */
	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly ?CoreSelfUpdateStatus $updates = null,
		private readonly ?Closure $github_observation = null,
		private readonly ?AdminAddOnRegistry $add_ons = null
	) {
	}

	/** @return array{state:string,admitted:bool,selected:string,version:string,provider_api:int,compatibility_revision:string,identity:?array{provider_code:string,selection_generation:int,implementation_fingerprint:string}} */
	public function github_selection(): array {
		if ( null === $this->github_observed ) {
			$this->github_observed = array(
				'state'                  => 'storage_unavailable',
				'admitted'               => false,
				'selected'               => 'unknown',
				'version'                => 'Unknown',
				'provider_api'           => 0,
				'compatibility_revision' => 'Unknown',
				'identity'               => null,
			);
			if ( null !== $this->github_observation ) {
				try {
					$this->github_observed = ( $this->github_observation )();
				} catch ( \Throwable ) {
					$this->github_observed['state'] = 'storage_unavailable';
				}
			}
		}
		return $this->github_observed;
	}

	/** @return array<string,string> */
	public function environment(): array {
		global $wp_version, $wpdb;
		try {
			$database = self::version( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : null );
		} catch ( \Throwable ) {
			$database = 'Unknown';
		}
		$rows               = array(
			'WordPress' => self::version( $wp_version ?? null ),
			'PHP'       => self::version( PHP_VERSION ),
			'OS family' => PHP_OS_FAMILY,
			'PHP SAPI'  => PHP_SAPI,
			'Database'  => $database,
		);
		$banner             = $_SERVER['SERVER_SOFTWARE'] ?? '';
		$rows['Web server'] = is_string( $banner ) && preg_match( '/\b(Apache|nginx|LiteSpeed|Microsoft-IIS)(?:\/([0-9.]{1,24}))?/i', substr( $banner, 0, 256 ), $match ) === 1
			? $match[1] . ( isset( $match[2] ) ? '/' . $match[2] : '' ) : 'Unknown';
		foreach ( array( 'memory_limit', 'max_execution_time', 'post_max_size', 'upload_max_filesize', 'max_input_vars' ) as $key ) {
			$value        = ini_get( $key );
			$rows[ $key ] = is_string( $value ) && preg_match( '/\A-?[0-9]{1,12}[KMG]?\z/i', $value ) === 1 ? $value : 'Unknown';
		}
		foreach ( array( 'zip', 'sodium', 'curl', 'json', 'openssl', 'mbstring' ) as $extension ) {
			$rows[ 'PHP extension ' . $extension ] = extension_loaded( $extension ) ? 'Present' : 'Absent';
		}
		foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'DISALLOW_FILE_MODS', 'AUTOMATIC_UPDATER_DISABLED' ) as $flag ) {
			$rows[ $flag ] = defined( $flag ) ? ( (bool) constant( $flag ) ? 'Enabled' : 'Disabled' ) : 'Default';
		}
		$method                    = defined( 'FS_METHOD' ) ? constant( 'FS_METHOD' ) : null;
		$rows['Filesystem method'] = in_array( $method, array( 'direct', 'ssh2', 'ftpext', 'ftpsockets' ), true ) ? $method : 'Automatic; not tested';
		$environment               = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null;
		$rows['Environment type']  = in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ? $environment : 'Unknown';
		return $rows;
	}

	public function components_truncated(): bool {
		return count( $this->providers->metadata() ) + count( $this->add_ons?->all() ?? array() ) > 32;
	}

	/** @return array<string,int|string|null> */
	public function core(): array {
		return array( 'installed_version' => self::version( get_file_data( dirname( __DIR__, 2 ) . '/ran-booster.php', array( 'Version' => 'Version' ) )['Version'] ?? null ) )
			+ ( $this->updates?->diagnostics() ?? array() );
	}

	/** @return list<array<string,string>> */
	public function components(): array {
		$rows = array();
		foreach ( array_slice( $this->providers->metadata(), 0, 32 ) as $code => $metadata ) {
			$rows[] = array(
				'Name'           => $metadata->label,
				'Provider'       => $code,
				'Admission'      => 'Admitted',
				'Selection'      => 'Selected locally',
				'Availability'   => 'Available locally; remote access not checked',
				'Implementation' => $this->implementation( $code ),
				'Archive API'    => (string) $metadata->archive_api_version,
			);
		}
		foreach ( array_slice( $this->add_ons?->all() ?? array(), 0, 32 - count( $rows ) ) as $tab ) {
			$rows[] = array(
				'Name'         => $tab->label(),
				'Admission'    => 'Admitted',
				'Selection'    => 'Not applicable',
				'Active'       => 'Yes',
				'Version'      => 'Unknown',
				'Availability' => 'Available locally; remote access not checked',
			);
		}
		foreach ( array(
			'ran-booster-bitbucket/ran-booster-bitbucket.php' => 'Bitbucket Cloud',
			'ran-booster-wp-pusher-migrator/ran-booster-wp-pusher-migrator.php' => 'WP Pusher Migrator',
		) as $file => $label ) {
			$path      = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $file : '';
			$installed = '' !== $path && is_file( $path );
			$rows[]    = array(
				'Name'      => $label,
				'Installed' => $installed ? 'Yes' : 'No',
				'Active'    => function_exists( 'is_plugin_active' ) ? ( is_plugin_active( $file ) ? 'Yes' : 'No' ) : 'Unknown',
				'Version'   => $installed ? self::version( get_file_data( $path, array( 'Version' => 'Version' ) )['Version'] ?? null ) : 'Not installed',
			);
		}
		return $rows;
	}

	/** Internal attribution; never export a concrete provider class or this token. */
	public function implementation( string $code ): string {
		$provider = $this->providers->all()[ $code ] ?? null;
		$parts    = array( null === $provider ? 'unavailable' : $provider::class );
		if ( 'gh' === $code ) {
			$selection = $this->github_selection()['identity'];
			if ( null !== $selection ) {
				$parts[] = (string) $selection['selection_generation'];
				$parts[] = $selection['implementation_fingerprint'];
			}
		}
		if ( null !== $provider ) {
			$file = ( new \ReflectionClass( $provider ) )->getFileName();
			if ( is_string( $file ) && is_file( $file ) ) {
				$parts[] = $file;
				$parts[] = (string) filemtime( $file );
				$parts[] = (string) filesize( $file );
			}
		}
		return hash( 'sha256', implode( '|', $parts ) );
	}

	public static function version( mixed $value ): string {
		return is_string( $value ) && preg_match( '/\A[0-9]+(?:\.[0-9]+)*(?:-(?:alpha|beta|rc|dev)(?:[.-][0-9]+)?)?\z/iD', $value ) === 1 && strlen( $value ) <= 80 ? $value : 'Unknown';
	}
}

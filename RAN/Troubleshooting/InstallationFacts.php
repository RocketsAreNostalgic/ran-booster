<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use Closure;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Booster;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Runtime\RuntimeSupport;
use RAN\Settings\GeneralPreferenceStore;

/** Fixed local observations, shared by Installation and the support capture. */
final class InstallationFacts {
	/** @var array{state:string,admitted:bool,selected:string,version:string,provider_api:int,compatibility_revision:string,identity:?array{provider_code:string,selection_generation:int,implementation_fingerprint:string}}|null */
	private ?array $github_observed = null;

	/** @var list<array<string,string>>|null */
	private ?array $component_observed = null;

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
		foreach ( array( 'max_input_time', 'default_socket_timeout', 'max_file_uploads', 'error_reporting' ) as $key ) {
			$rows[ 'PHP INI ' . $key ] = self::ini_number( $key );
		}
		foreach ( array( 'file_uploads', 'allow_url_fopen', 'display_errors', 'log_errors', 'opcache.enable' ) as $key ) {
			$rows[ 'PHP INI ' . $key ] = self::ini_boolean( $key );
		}
		foreach ( array( 'open_basedir', 'sys_temp_dir', 'upload_tmp_dir', 'curl.cainfo', 'openssl.cafile', 'openssl.capath' ) as $key ) {
			$rows[ 'PHP INI ' . $key ] = self::ini_presence( $key );
		}
		$upload_limit                       = function_exists( 'wp_max_upload_size' ) ? wp_max_upload_size() : null;
		$rows['WordPress memory limit']     = self::defined_scalar( 'WP_MEMORY_LIMIT' );
		$rows['WordPress max memory limit'] = self::defined_scalar( 'WP_MAX_MEMORY_LIMIT' );
		$rows['Effective upload limit']     = is_int( $upload_limit ) && $upload_limit >= 0 ? (string) $upload_limit . ' bytes' : 'Unknown';
		$rows['Multisite']                  = function_exists( 'is_multisite' ) && is_multisite() ? 'Enabled; unsupported' : 'Disabled';
		$rows['Runtime support']            = $this->is_runtime_supported() ? 'Supported' : 'Unsupported';
		$rows['Request channel']            = defined( 'WP_CLI' ) && WP_CLI ? 'CLI' : ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ? 'cron' : ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ? 'AJAX' : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'REST' : 'web' ) ) );
		$rows['Administration context']     = function_exists( 'is_network_admin' ) && is_network_admin() ? 'network' : ( function_exists( 'is_admin' ) && is_admin() ? 'site' : 'none' );
		foreach ( array( 'zip', 'sodium', 'curl', 'json', 'openssl', 'mbstring' ) as $extension ) {
			$rows[ 'PHP extension ' . $extension ] = extension_loaded( $extension ) ? 'Present' : 'Absent';
		}
		foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'DISALLOW_FILE_MODS', 'AUTOMATIC_UPDATER_DISABLED' ) as $flag ) {
			$rows[ $flag ] = defined( $flag ) ? ( (bool) constant( $flag ) ? 'Enabled' : 'Disabled' ) : 'Default';
		}
		foreach ( array( 'WP_HTTP_BLOCK_EXTERNAL', 'WP_CACHE' ) as $flag ) {
			$rows[ $flag ] = defined( $flag ) ? ( (bool) constant( $flag ) ? 'Enabled' : 'Disabled' ) : 'Default';
		}
		$rows['Proxy configured']      = defined( 'WP_PROXY_HOST' ) || defined( 'WP_PROXY_PORT' ) ? 'Yes' : 'No';
		$rows['External object cache'] = function_exists( 'wp_using_ext_object_cache' ) ? ( wp_using_ext_object_cache() ? 'Enabled' : 'Disabled' ) : 'Unknown';
		$rows['ZipArchive']            = class_exists( 'ZipArchive' ) ? 'Available' : 'Unavailable';
		$rows['Sodium XChaCha20']      = function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ? 'Available' : 'Unavailable';
		foreach ( array( 'flock', 'rename', 'chmod', 'random_bytes', 'fsync' ) as $function ) {
			$rows[ 'PHP function ' . $function ] = function_exists( $function ) ? 'Available' : 'Unavailable';
		}
		$rows['cURL library']      = self::library_version( function_exists( 'curl_version' ) ? ( curl_version()['version'] ?? null ) : null );
		$rows['OpenSSL library']   = self::library_version( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : null );
		$rows['libzip library']    = self::library_version( class_exists( 'ZipArchive' ) && defined( 'ZipArchive::LIBZIP_VERSION' ) ? \ZipArchive::LIBZIP_VERSION : null );
		$rows['Sodium library']    = self::library_version( defined( 'SODIUM_LIBRARY_VERSION' ) ? SODIUM_LIBRARY_VERSION : null );
		$method                    = defined( 'FS_METHOD' ) ? constant( 'FS_METHOD' ) : null;
		$rows['Filesystem method'] = in_array( $method, array( 'direct', 'ssh2', 'ftpext', 'ftpsockets' ), true ) ? $method : 'Automatic; not tested';
		$environment               = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null;
		$rows['Environment type']  = in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ? $environment : 'Unknown';
		return $rows;
	}

	private static function ini_number( string $key ): string {
		$value = ini_get( $key );
		if ( ! is_string( $value ) ) {
			return 'Unknown';
		}
		return preg_match( '/\A-?[0-9]{1,12}\z/', $value ) === 1 ? $value : 'Unknown';
	}

	private static function ini_boolean( string $key ): string {
		$value = ini_get( $key );
		if ( ! is_string( $value ) ) {
			return 'Unknown';
		}
		return in_array( strtolower( $value ), array( '1', 'on', 'true', 'yes' ), true ) ? 'Enabled' : ( in_array( strtolower( $value ), array( '', '0', 'off', 'false', 'no' ), true ) ? 'Disabled' : 'Unknown' );
	}

	private static function ini_presence( string $key ): string {
		$value = ini_get( $key );
		return is_string( $value ) && '' !== trim( $value ) ? 'Configured; value withheld' : 'Default';
	}

	private static function library_version( mixed $value ): string {
		return is_string( $value ) && preg_match( '/\A[A-Za-z0-9 ._+-]{1,120}\z/', $value ) === 1 ? $value : 'Unknown';
	}

	private static function defined_scalar( string $name ): string {
		if ( ! defined( $name ) ) {
			return 'Default';
		}
		$value = constant( $name );
		if ( is_bool( $value ) ) {
			return 'Invalid';
		}
		return is_int( $value ) || ( is_string( $value ) && preg_match( '/\A[0-9]{1,12}[KMG]?\z/i', $value ) === 1 ) ? (string) $value : 'Invalid';
	}

	private function is_runtime_supported(): bool {
		global $wp_version;
		return RuntimeSupport::current()->allows_managed_operations() && version_compare( PHP_VERSION, '8.2', '>=' ) && is_string( $wp_version ?? null ) && version_compare( $wp_version, '7.0', '>=' );
	}

	public function components_truncated(): bool {
		return count( $this->component_rows() ) > 32;
	}

	/** @return array<string,int|string|null> */
	public function core(): array {
		return array( 'installed_version' => self::version( get_file_data( dirname( __DIR__, 2 ) . '/ran-booster.php', array( 'Version' => 'Version' ) )['Version'] ?? null ) )
			+ ( $this->updates?->diagnostics() ?? array() );
	}

	/** @return array<string,string> Fixed Core configuration projection; secret/path values never enter it. */
	public function core_configuration(): array {
		$rows = array(
			'RAN_BOOSTER_PROVIDER_API_VERSION'          => self::exact_integer_constant( 'RAN_BOOSTER_PROVIDER_API_VERSION', 15 ),
			'RAN_BOOSTER_ADDON_API_VERSION'             => self::exact_integer_constant( 'RAN_BOOSTER_ADDON_API_VERSION', 17 ),
			'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => self::exact_integer_constant( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3 ),
			'RAN_BOOSTER_PORTABILITY_API_VERSION'       => self::exact_integer_constant( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 4 ),
			'RAN_BOOSTER_RUNTIME_MODE'                  => self::choice_constant( 'RAN_BOOSTER_RUNTIME_MODE', array( 'single_site_supported', 'multisite_unsupported' ) ),
		);
		try {
			foreach ( ( new GeneralPreferenceStore() )->projection() as $key => $field ) {
				$name  = array(
					'hide_github'           => 'RAN_BOOSTER_HIDE_GITHUB_UI',
					'self_update_discovery' => 'RAN_BOOSTER_SELF_UPDATE_MODE',
					'history_rows'          => 'RAN_BOOSTER_HISTORY_ROWS',
					'history_days'          => 'RAN_BOOSTER_HISTORY_DAYS',
					'max_archive_bytes'     => 'RAN_BOOSTER_MAX_ARCHIVE_BYTES',
				)[ $key ];
				$value = is_bool( $field['value'] ) ? ( $field['value'] ? 'Enabled' : 'Disabled' ) : (string) $field['value'];
				if ( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' === $name && 'invalid_code' === ( $field['error'] ?? null ) ) {
					$rows[ $name ] = 'Invalid code value; archive admission refused';
					continue;
				}
				if ( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' === $name && null !== ( $field['error'] ?? null ) ) {
					$rows[ $name ] = 'Invalid saved value; effective default ' . $value;
					continue;
				}
				if ( 'RAN_BOOSTER_HIDE_GITHUB_UI' === $name ) {
					$rows[ $name ]  = ( $field['error'] ?? null ) ? 'Invalid ' . $field['source'] . ' value; requested ' . $value : 'Requested ' . $value;
					$rows[ $name ] .= '; source=' . $field['source'] . ( $field['locked'] ? '; locked' : '' );
					continue;
				}
				$rows[ $name ] = ( $field['error'] ?? null ) ? 'Invalid; effective default ' . $value : $value . '; source=' . $field['source'] . ( $field['locked'] ? '; locked' : '' );
			}
		} catch ( \Throwable ) {
			$rows += array_fill_keys( array( 'RAN_BOOSTER_HIDE_GITHUB_UI', 'RAN_BOOSTER_SELF_UPDATE_MODE', 'RAN_BOOSTER_HISTORY_ROWS', 'RAN_BOOSTER_HISTORY_DAYS', 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ), 'Unavailable' );
		}
		if ( defined( 'RAN_BOOSTER_SELF_UPDATE_MODE' ) ) {
			$raw                                  = constant( 'RAN_BOOSTER_SELF_UPDATE_MODE' );
			$mode                                 = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
			$rows['RAN_BOOSTER_SELF_UPDATE_MODE'] = in_array( $mode, array( 'auto', 'enabled', 'disabled' ), true ) ? $mode . '; source=code; locked' : 'Invalid; source=code; locked';
		}
		foreach ( array( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', 'RAN_BOOSTER_GITHUB_TOKEN' ) as $name ) {
			$rows[ $name ] = defined( $name ) ? 'Configured; value withheld' : 'Undefined';
		}
		return $rows;
	}

	private static function exact_integer_constant( string $name, int $expected ): string {
		return defined( $name ) && constant( $name ) === $expected ? (string) $expected : ( defined( $name ) ? 'Invalid' : 'Undefined' );
	}

	/** @param list<string> $allowed */
	private static function choice_constant( string $name, array $allowed ): string {
		$value = defined( $name ) ? constant( $name ) : null;
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : ( defined( $name ) ? 'Invalid' : 'Undefined' );
	}

	/** @return list<array<string,string>> */
	public function components(): array {
		return array_slice( $this->component_rows(), 0, 32 );
	}

	/** @return list<array<string,string>> */
	private function component_rows(): array {
		if ( null !== $this->component_observed ) {
			return $this->component_observed;
		}
		$rows = array();
		if ( null !== $this->github_observation && ! isset( $this->providers->metadata()['gh'] ) ) {
			$rows['provider:gh'] = array(
				'Name'     => 'GitHub',
				'Provider' => 'gh',
			);
		}
		foreach ( $this->providers->metadata() as $code => $metadata ) {
			if ( count( $rows ) >= 33 ) {
				break;
			}
			$rows[ 'provider:' . $code ] = array(
				'Name'           => $metadata->label,
				'Provider'       => $code,
				'Admission'      => 'Admitted',
				'Selection'      => 'Selected locally',
				'Availability'   => 'Available locally; remote access not checked',
				'Implementation' => $this->implementation( $code ),
				'Archive API'    => (string) $metadata->archive_api_version,
			);
			$provider                    = $this->providers->all()[ $code ] ?? null;
			$capabilities                = array();
			foreach ( null === $provider ? array() : class_implements( $provider ) as $interface ) {
				if ( str_starts_with( $interface, 'RAN\\RepositoryProvider\\' ) && is_a( $interface, \RAN\Provider\ProviderCapability::class, true ) ) {
					$capabilities[] = substr( $interface, strlen( 'RAN\\RepositoryProvider\\' ) );
				}
			}
			if ( array() !== $capabilities ) {
				$rows[ 'provider:' . $code ]['Capabilities'] = implode( ', ', $capabilities );
			}
		}
		$catalogue      = defined( 'WP_PLUGIN_DIR' ) ? Booster::extension_declarations() : array();
		$bitbucket_file = '';
		foreach ( $catalogue as $extension ) {
			if ( 'ran-booster-bitbucket' === $extension['id'] && is_string( $extension['plugin'] ) ) {
				$bitbucket_file = WP_PLUGIN_DIR . '/' . $extension['plugin'];
			}
		}
		foreach ( $this->add_ons?->all() ?? array() as $tab ) {
			$key = 'ran-booster-bitbucket' === $tab->add_on_slug() && isset( $rows['provider:bb'] ) && '' !== $bitbucket_file && is_file( $bitbucket_file ) ? 'provider:bb' : 'addon:' . $tab->add_on_slug();
			if ( count( $rows ) >= 33 && ! isset( $rows[ $key ] ) ) {
				break;
			}
			$addon        = array(
				'Name'      => $tab->label(),
				'Admission' => 'Admitted',
				'Selection' => 'Not applicable',
				'Active'    => 'Yes',
				'Version'   => 'Unknown',
				'Add-on'    => $tab->add_on_slug(),
				'Tab'       => $tab->key(),
				'Facade'    => $tab->facade_name() ?? 'None',
			);
			$rows[ $key ] = ( $rows[ $key ] ?? array() ) + $addon;
		}
		foreach ( $catalogue as $extension ) {
			$id   = $extension['id'];
			$file = $extension['plugin'];
			if ( ! is_string( $id ) || ! is_string( $file ) ) {
				continue;
			}
			$key = 'ran-booster-bitbucket' === $id && isset( $rows['provider:bb'] ) ? 'provider:bb' : 'addon:' . $id;
			if ( count( $rows ) >= 33 && ! isset( $rows[ $key ] ) ) {
				continue;
			}
			$path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $file : '';
			if ( '' === $path || ! is_file( $path ) ) {
				continue;
			}
			$package      = array(
				'Name'      => is_string( $extension['name'] ) ? $extension['name'] : $id,
				'Installed' => 'Yes',
				'Active'    => function_exists( 'is_plugin_active' ) ? ( is_plugin_active( $file ) ? 'Yes' : 'No' ) : 'Unknown',
				'Version'   => self::version( get_file_data( $path, array( 'Version' => 'Version' ) )['Version'] ?? null ),
			);
			$rows[ $key ] = array_replace( $rows[ $key ] ?? array(), $package );

		}
		$this->component_observed = array_values( $rows );
		return $this->component_observed;
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

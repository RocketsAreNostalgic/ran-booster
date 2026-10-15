<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

/** Passive, bounded contract data. It conveys no permission to use credentials. */
final readonly class GitHubReplacementDeclaration {
	public const API_CREDENTIAL_FORMATS = array( 'github-classic-pat-v1', 'github-fine-grained-pat-v1' );
	public const FACETS                 = array(
		ProviderCredentialPolicySupplier::class,
		CredentialValidator::class,
		RepositoryBrowser::class,
		CredentialedPublicRepositoryBrowser::class,
		WebhookNormalizer::class,
		RepositoryWebhookFitness::class,
		RepositoryWebhookManagement::class,
		RepositoryWebhookSettingsLink::class,
		RepositoryPathInspector::class,
		RepositoryReleaseCandidateListing::class,
		RepositoryReleaseInspector::class,
		RepositoryReleaseAcquirer::class,
		RepositoryReleaseMetadata::class,
		RepositoryReleaseNativeTargets::class,
		RepositoryReleaseWorkflowManagementV3::class,
		RepositoryReleaseWorkflowManagementV4::class,
	);
	public string $plugin_basename;
	public string $implementation_id;
	public string $compatibility_revision;
	public int $provider_api_version;
	/** @var list<string> */
	public array $api_credential_formats;
	public ?string $webhook_signing_format;
	/** @var list<string> */
	public array $facets;
	public string $implementation_fingerprint;

	/** @param array<string, mixed> $manifest Closed, version-one manifest. */
	public function __construct( array $manifest ) {
		$keys = array( 'plugin_basename', 'implementation_id', 'compatibility_revision', 'provider_api_version', 'api_credential_formats', 'webhook_signing_format', 'facets' );
		if ( count( $keys ) !== count( $manifest ) || array_diff( $keys, array_keys( $manifest ) ) ) {
			throw new \InvalidArgumentException( 'Invalid GitHub replacement declaration.' );
		}
		foreach ( array( 'plugin_basename', 'implementation_id', 'compatibility_revision' ) as $key ) {
			if ( ! is_string( $manifest[ $key ] ) || strlen( $manifest[ $key ] ) > 191 || ! preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9_.\/-]*\z/D', $manifest[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Invalid GitHub replacement identity.' );
			}
		}
		$basename = $manifest['plugin_basename'];
		if ( ! preg_match( '/\A(?:[a-zA-Z0-9][a-zA-Z0-9_.-]*\/)?[a-zA-Z0-9][a-zA-Z0-9_.-]*\.php\z/D', $basename ) || 15 !== $manifest['provider_api_version'] ) {
			throw new \InvalidArgumentException( 'Unsupported GitHub replacement contract.' );
		}
		if ( null !== $manifest['webhook_signing_format'] && 'github-hmac-sha256-v1' !== $manifest['webhook_signing_format'] ) {
			throw new \InvalidArgumentException( 'Unsupported GitHub signing format.' );
		}
		$this->plugin_basename            = $basename;
		$this->implementation_id          = $manifest['implementation_id'];
		$this->compatibility_revision     = $manifest['compatibility_revision'];
		$this->provider_api_version       = 15;
		$this->api_credential_formats     = self::closed_set( $manifest['api_credential_formats'], self::API_CREDENTIAL_FORMATS );
		$this->webhook_signing_format     = $manifest['webhook_signing_format'];
		$this->facets                     = self::closed_set( $manifest['facets'], self::FACETS );
		$this->implementation_fingerprint = hash(
			'sha256',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical scalar-only contract encoding must throw rather than sanitize invalid bytes.
			json_encode(
				array(
					'github.com',
					$this->plugin_basename,
					$this->implementation_id,
					$this->compatibility_revision,
					$this->provider_api_version,
					$this->api_credential_formats,
					$this->webhook_signing_format,
					$this->facets,
				),
				JSON_THROW_ON_ERROR
			)
		);
	}

	/**
	 * @param list<string> $allowed
	 * @return list<string>
	 */
	private static function closed_set( mixed $values, array $allowed ): array {
		if ( ! is_array( $values ) || ! array_is_list( $values ) || count( $values ) > count( $allowed ) ) {
			throw new \InvalidArgumentException( 'Invalid GitHub replacement contract set.' );
		}
		$result = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) || in_array( $value, $result, true ) ) {
				throw new \InvalidArgumentException( 'Unsupported GitHub replacement contract value.' );
			}
			$result[] = $value;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Read one active main file without executing it. Missing/invalid files throw;
	 * null means a readable plugin does not declare a replacement header.
	 * Directory symlinks are supported; manifest symlinks outside that directory are not.
	 */
	public static function from_plugin_file( string $plugin_root, string $basename ): ?self {
		if ( ! preg_match( '/\A(?:[a-zA-Z0-9][a-zA-Z0-9_.-]*\/)?[a-zA-Z0-9][a-zA-Z0-9_.-]*\.php\z/D', $basename ) ) {
			throw new \InvalidArgumentException( 'Invalid plugin basename.' );
		}
		$root = realpath( $plugin_root );
		if ( false === $root ) {
			throw new \InvalidArgumentException( 'Plugin root is unavailable.' );
		}
		$main = $root . '/' . $basename;
		clearstatcache( true, $main );
		if ( ! is_file( $main ) || ! is_readable( $main ) || dirname( (string) realpath( $main ) ) !== realpath( dirname( $main ) ) ) {
			throw new \InvalidArgumentException( 'GitHub replacement main file is unavailable.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read bounded local plugin headers without executing candidate PHP or performing remote I/O.
		$header = file_get_contents( $main, false, null, 0, 8192 );
		if ( false === $header ) {
			throw new \InvalidArgumentException( 'GitHub replacement main file is unavailable.' );
		}
		if ( ! preg_match( '/^[ \t\/*#@]*RAN Booster GitHub Replacement[ \t]*:(.*)$/mi', str_replace( "\r", "\n", $header ), $match ) ) {
			return null;
		}
		if ( '1' !== trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) ?? '' ) ) {
			throw new \InvalidArgumentException( 'Unsupported GitHub replacement header.' );
		}
		$manifest_path = substr( $main, 0, -4 ) . '.gh-replacement.json';
		clearstatcache( true, $manifest_path );
		$resolved = realpath( $manifest_path );
		if ( false === $resolved || dirname( $resolved ) !== realpath( dirname( $main ) ) ) {
			throw new \InvalidArgumentException( 'GitHub replacement manifest is unavailable.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the bounded adjacent local passive manifest, never a remote resource.
		$json = file_get_contents( $resolved, false, null, 0, 16385 );
		if ( false === $json || strlen( $json ) > 16384 ) {
			throw new \InvalidArgumentException( 'Invalid GitHub replacement manifest size.' );
		}
		$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new \InvalidArgumentException( 'Invalid GitHub replacement manifest.' );
		}
		$declaration = new self( $data );
		if ( $basename !== $declaration->plugin_basename ) {
			throw new \InvalidArgumentException( 'GitHub replacement identity does not match its main file.' );
		}
		return $declaration;
	}
}

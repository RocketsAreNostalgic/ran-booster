<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use ZipArchive;

/**
 * Verified Consumer API 2 template pack. It parses and renders inert bytes but
 * cannot write a repository, call GitHub or execute a downloaded member.
 */
final readonly class TemplatePack {

	public const CONSUMER_API  = 2;
	public const MANIFEST_PATH = 'template-pack.json';

	private const MAX_ARCHIVE_BYTES  = 2097152;
	private const MAX_MANIFEST_BYTES = 65536;
	private const MAX_MEMBER_BYTES   = 262144;
	private const MAX_TOTAL_BYTES    = 1048576;
	private const MAX_MEMBERS        = 32;

	private const PROFILES = array(
		'source-ready-wordpress-plugin/2',
		'source-ready-wordpress-theme/2',
	);

	/** @var array<string, array<string, string>> */
	private const ENTRY_PLACEHOLDERS = array(
		'release-workflow'             => array(
			'DEFAULT_BRANCH' => 'branch',
			'PACKAGE_SLUG'   => 'slug',
		),
		'release-please-config'        => array(
			'BASE_SHA'         => 'sha',
			'EXTRA_FILES_JSON' => 'json_fragment',
			'PACKAGE_SLUG'     => 'slug',
		),
		'build-release-script'         => array(
			'HEADER_PATH'  => 'path',
			'PACKAGE_SLUG' => 'slug',
			'PACKAGE_TYPE' => 'package_type',
		),
		'verify-release-script'        => array(
			'HEADER_PATH'  => 'path',
			'PACKAGE_SLUG' => 'slug',
			'PACKAGE_TYPE' => 'package_type',
			'UPDATE_URI'   => 'github_uri',
		),
		'upload-release-assets-script' => array(),
	);

	/** @var list<string> */
	private const FORBIDDEN_CAPABILITY_KEYS = array(
		'destination',
		'mode',
		'mutation',
		'operation',
		'ownership',
		'permissions',
		'target',
		'target_path',
		'triggers',
		'workflow_events',
	);

	/** @param array<string, mixed> $identity @param array<string, array<string, array{content:string,sha256:string}>> $profiles */
	private function __construct(
		private array $identity,
		private string $packVersion,
		private array $profiles,
		private string $manifestHash
	) {
	}

	/**
	 * @param array<string, mixed> $identity
	 * @return array{code:string, pack?:self}
	 */
	public static function fromArchive( string $archive, array $identity ): array {
		if ( ! self::validIdentity( $identity, $archive ) ) {
			return array( 'code' => 'template_pack_invalid' );
		}

		$path = tempnam( sys_get_temp_dir(), 'ran-template-pack-' );
		if ( false === $path ) {
			return array( 'code' => 'template_pack_unavailable' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact bounded temporary archive.
			if ( strlen( $archive ) !== file_put_contents( $path, $archive ) ) {
				return array( 'code' => 'template_pack_unavailable' );
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
				return array( 'code' => 'template_pack_invalid' );
			}

			try {
				$members = self::members( $zip );
				if ( null === $members || ! isset( $members[ self::MANIFEST_PATH ] ) ) {
					return array( 'code' => 'template_pack_invalid' );
				}
				$manifestBytes = $members[ self::MANIFEST_PATH ];
				if ( strlen( $manifestBytes ) > self::MAX_MANIFEST_BYTES ) {
					return array( 'code' => 'template_pack_invalid' );
				}
				$manifest = self::decodeManifest( $manifestBytes );
				if ( null === $manifest ) {
					return array( 'code' => 'template_pack_invalid' );
				}
				if ( ! self::manifestIdentityMatches( $manifest, $identity ) ) {
					return array( 'code' => 'template_pack_invalid' );
				}
				if ( self::CONSUMER_API !== $manifest['consumer_api'] ) {
					return array( 'code' => 'template_pack_incompatible' );
				}
				$profiles = self::verifiedProfiles( $manifest['profiles'], $members );
				if ( null === $profiles ) {
					return array( 'code' => 'template_pack_invalid' );
				}

				return array(
					'code' => 'ok',
					'pack' => new self( $identity, $manifest['pack_version'], $profiles, hash( 'sha256', $manifestBytes ) ),
				);
			} finally {
				$zip->close();
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the exact temporary file created above.
			unlink( $path );
		}
	}

	/** @return array<string, mixed> */
	public function identity(): array {
		return $this->identity;
	}

	public function packVersion(): string {
		return $this->packVersion;
	}

	public function manifestHash(): string {
		return $this->manifestHash;
	}

	/** @return list<string> */
	public function profiles(): array {
		return array_keys( $this->profiles );
	}

	/**
	 * Literal substitution only. Downloaded bytes are never evaluated.
	 *
	 * @param array<string, mixed> $values
	 * @return array{code:string, content?:string, sha256?:string}
	 */
	public function render( string $profile, string $logicalId, array $values ): array {
		if ( ! isset( $this->profiles[ $profile ][ $logicalId ], self::ENTRY_PLACEHOLDERS[ $logicalId ] ) ) {
			return array( 'code' => 'invalid_render' );
		}
		$expected = self::ENTRY_PLACEHOLDERS[ $logicalId ];
		if ( array_keys( $values ) !== array_keys( $expected ) ) {
			return array( 'code' => 'invalid_render' );
		}

		$tokens       = array();
		$replacements = array();
		foreach ( $expected as $name => $type ) {
			$value = $values[ $name ];
			if ( ! is_string( $value ) || ! self::validPlaceholderValue( $type, $value ) ) {
				return array( 'code' => 'invalid_render' );
			}
			$tokens[]       = '{{RAN_' . $name . '}}';
			$replacements[] = $value;
		}

		$content = str_replace( $tokens, $replacements, $this->profiles[ $profile ][ $logicalId ]['content'] );
		if ( 1 === preg_match( '/\{\{RAN_[A-Z][A-Z0-9_]*\}\}/', $content ) ) {
			return array( 'code' => 'invalid_render' );
		}

		return array(
			'code'    => 'ok',
			'content' => $content,
			'sha256'  => hash( 'sha256', $content ),
		);
	}

	/** @return array<string, string>|null */
	private static function members( ZipArchive $zip ): ?array {
		if ( $zip->numFiles < 2 || $zip->numFiles > self::MAX_MEMBERS ) {
			return null;
		}
		$members = array();
		$total   = 0;
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
			if ( ! is_array( $stat ) || ! is_string( $stat['name'] ?? null ) || ! is_int( $stat['size'] ?? null )
				|| ! is_int( $stat['comp_size'] ?? null ) || ! is_int( $stat['comp_method'] ?? null )
				|| ! self::validMemberPath( $stat['name'] ) || $stat['size'] < 1 || $stat['size'] > self::MAX_MEMBER_BYTES
				|| $stat['comp_size'] < 0 || ! in_array( $stat['comp_method'], array( ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE ), true )
				|| isset( $members[ $stat['name'] ] ) ) {
				return null;
			}
			$total += $stat['size'];
			if ( $total > self::MAX_TOTAL_BYTES || ( $stat['size'] > 4096 && $stat['size'] > max( 1, $stat['comp_size'] ) * 200 ) ) {
				return null;
			}
			$attributes = 0;
			$operations = 0;
			if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes )
				|| ZipArchive::OPSYS_UNIX !== $operations || 0100644 !== ( ( $attributes >> 16 ) & 0xffff ) ) {
				return null;
			}
			$content = $zip->getFromIndex( $index, $stat['size'], ZipArchive::FL_UNCHANGED );
			if ( ! is_string( $content ) || strlen( $content ) !== $stat['size']
				|| str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
				return null;
			}
			$members[ $stat['name'] ] = $content;
		}

		return $members;
	}

	/** @return array<string, mixed>|null */
	private static function decodeManifest( string $bytes ): ?array {
		try {
			$manifest = json_decode( $bytes, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_array( $manifest ) || array_keys( $manifest ) !== array( 'schema_version', 'consumer_api', 'pack_version', 'repository', 'release', 'profiles' )
			|| 1 !== $manifest['schema_version'] || ! is_int( $manifest['consumer_api'] ) || ! self::validStableVersion( $manifest['pack_version'] ?? null )
			|| ! is_array( $manifest['repository'] ?? null ) || array_keys( $manifest['repository'] ) !== array( 'name', 'id' )
			|| ! is_array( $manifest['release'] ?? null ) || array_keys( $manifest['release'] ) !== array( 'id', 'tag', 'commit' )
			|| ! is_array( $manifest['profiles'] ?? null ) || array() === $manifest['profiles']
			|| self::containsForbiddenCapability( $manifest ) ) {
			return null;
		}

		return $manifest;
	}

	/** @param array<string, mixed> $manifest @param array<string, mixed> $identity */
	private static function manifestIdentityMatches( array $manifest, array $identity ): bool {
		return hash_equals( $identity['repository_name'], (string) ( $manifest['repository']['name'] ?? '' ) )
			&& hash_equals( $identity['repository_id'], (string) ( $manifest['repository']['id'] ?? '' ) )
			&& $identity['release_id'] === ( $manifest['release']['id'] ?? null )
			&& hash_equals( $identity['release_tag'], (string) ( $manifest['release']['tag'] ?? '' ) )
			&& hash_equals( $identity['release_commit'], (string) ( $manifest['release']['commit'] ?? '' ) )
			&& hash_equals( self::versionFromTag( $identity['release_tag'] ) ?? '', $manifest['pack_version'] );
	}

	/**
	 * @param array<string, mixed> $declared
	 * @param array<string, string> $members
	 * @return array<string, array<string, array{content:string,sha256:string}>>|null
	 */
	private static function verifiedProfiles( array $declared, array $members ): ?array {
		if ( array_keys( $declared ) !== self::PROFILES ) {
			return null;
		}
		$profiles      = array();
		$declaredPaths = array( self::MANIFEST_PATH => true );
		foreach ( self::PROFILES as $profile ) {
			$definition = $declared[ $profile ] ?? null;
			if ( ! is_array( $definition ) || array_keys( $definition ) !== array( 'profile_version', 'entries' )
				|| 1 !== $definition['profile_version'] || ! is_array( $definition['entries'] ?? null )
				|| array_keys( $definition['entries'] ) !== array_keys( self::ENTRY_PLACEHOLDERS ) ) {
				return null;
			}
			$profiles[ $profile ] = array();
			foreach ( $definition['entries'] as $logicalId => $entry ) {
				if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'path', 'size', 'sha256', 'placeholders' )
					|| ! is_string( $entry['path'] ?? null ) || ! self::validMemberPath( $entry['path'] )
					|| ! is_int( $entry['size'] ?? null ) || $entry['size'] < 1 || $entry['size'] > self::MAX_MEMBER_BYTES
					|| ! is_string( $entry['sha256'] ?? null ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $entry['sha256'] )
					|| ! is_array( $entry['placeholders'] ?? null ) || $entry['placeholders'] !== self::ENTRY_PLACEHOLDERS[ $logicalId ]
					|| ! isset( $members[ $entry['path'] ] ) || strlen( $members[ $entry['path'] ] ) !== $entry['size']
					|| ! hash_equals( $entry['sha256'], hash( 'sha256', $members[ $entry['path'] ] ) )
					|| ! self::templatePlaceholdersMatch( $members[ $entry['path'] ], array_keys( $entry['placeholders'] ) ) ) {
					return null;
				}
				$declaredPaths[ $entry['path'] ]    = true;
				$profiles[ $profile ][ $logicalId ] = array(
					'content' => $members[ $entry['path'] ],
					'sha256'  => $entry['sha256'],
				);
			}
		}
		$memberPaths = array_keys( $members );
		$knownPaths  = array_keys( $declaredPaths );
		sort( $memberPaths, SORT_STRING );
		sort( $knownPaths, SORT_STRING );

		return $memberPaths === $knownPaths ? $profiles : null;
	}

	/** @param list<string> $expected */
	private static function templatePlaceholdersMatch( string $content, array $expected ): bool {
		preg_match_all( '/\{\{RAN_([A-Z][A-Z0-9_]*)\}\}/', $content, $matches );
		$actual = array_values( array_unique( $matches[1] ?? array() ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );

		return $actual === $expected;
	}

	/** @param array<string, mixed> $identity */
	private static function validIdentity( array $identity, string $archive ): bool {
		$keys = array(
			'repository_name',
			'repository_id',
			'release_id',
			'release_tag',
			'release_commit',
			'release_target',
			'tag_target',
			'release_draft',
			'release_prerelease',
			'release_immutable',
			'asset_count',
			'asset_id',
			'asset_name',
			'asset_state',
			'asset_content_type',
			'asset_size',
			'asset_digest',
			'asset_sha256',
		);
		return array_keys( $identity ) === $keys
			&& 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates' === $identity['repository_name']
			&& '1322743261' === $identity['repository_id']
			&& is_int( $identity['release_id'] ) && $identity['release_id'] > 0
			&& is_string( $identity['release_tag'] ) && null !== self::versionFromTag( $identity['release_tag'] )
			&& is_string( $identity['release_commit'] ) && 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $identity['release_commit'] )
			&& $identity['release_commit'] === $identity['release_target'] && $identity['release_commit'] === $identity['tag_target']
			&& false === $identity['release_draft'] && false === $identity['release_prerelease'] && true === $identity['release_immutable']
			&& 1 === $identity['asset_count'] && is_int( $identity['asset_id'] ) && $identity['asset_id'] > 0
			&& 'ran-booster-release-bootstrap-templates.zip' === $identity['asset_name']
			&& 'uploaded' === $identity['asset_state'] && 'application/zip' === $identity['asset_content_type']
			&& is_int( $identity['asset_size'] ) && $identity['asset_size'] === strlen( $archive )
			&& $identity['asset_size'] > 0 && $identity['asset_size'] <= self::MAX_ARCHIVE_BYTES
			&& is_string( $identity['asset_sha256'] ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $identity['asset_sha256'] )
			&& 'sha256:' . $identity['asset_sha256'] === $identity['asset_digest']
			&& hash_equals( $identity['asset_sha256'], hash( 'sha256', $archive ) );
	}

	private static function containsForbiddenCapability( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) && in_array( $key, self::FORBIDDEN_CAPABILITY_KEYS, true ) ) {
				return true;
			}
			if ( self::containsForbiddenCapability( $child ) ) {
				return true;
			}
		}

		return false;
	}

	private static function validPlaceholderValue( string $type, string $value ): bool {
		$limit = 'json_fragment' === $type ? 16384 : 255;
		if ( '' === $value || strlen( $value ) > $limit || 1 === preg_match( '/[\x00-\x1F\x7F]|\{\{RAN_/', $value ) ) {
			return false;
		}
		return match ( $type ) {
			'branch' => 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $value ) && ! str_contains( $value, '..' ) && ! str_contains( $value, '//' ) && ! str_contains( $value, '@{' ) && ! str_ends_with( $value, '.lock' ),
			'slug' => 1 === preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,198}[a-z0-9])?\z/D', $value ),
			'sha' => 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $value ),
			'path' => self::validTargetPath( $value ),
			'package_type' => in_array( $value, array( 'plugin', 'theme' ), true ),
			'github_uri' => 1 === preg_match( '#\Ahttps://github\.com/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $value ),
			'json_fragment' => self::validExtraFilesJson( $value ),
			default => false,
		};
	}

	private static function validExtraFilesJson( string $value ): bool {
		try {
			$files = json_decode( $value, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \Throwable ) {
			return false;
		}
		if ( ! is_array( $files ) || ! array_is_list( $files ) || array() === $files || count( $files ) > 64 ) {
			return false;
		}
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! is_string( $file['type'] ?? null ) || ! is_string( $file['path'] ?? null )
				|| ! self::validTargetPath( $file['path'] ) ) {
				return false;
			}
			if ( 'generic' === $file['type'] && array_keys( $file ) === array( 'type', 'path' ) ) {
				continue;
			}
			if ( 'json' !== $file['type'] || array_keys( $file ) !== array( 'type', 'path', 'jsonpath' )
				|| '$.version' !== ( $file['jsonpath'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function validTargetPath( string $path ): bool {
		return strlen( $path ) <= 255 && 1 === preg_match( '#\A[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z#D', $path )
			&& ! str_contains( $path, '..' );
	}

	private static function validMemberPath( string $path ): bool {
		return strlen( $path ) <= 255 && self::validTargetPath( $path ) && ! str_ends_with( $path, '/' ) && ! str_contains( $path, '\\' );
	}

	private static function validStableVersion( mixed $version ): bool {
		return is_string( $version ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version );
	}

	private static function versionFromTag( string $tag ): ?string {
		$version = str_starts_with( $tag, 'v' ) ? substr( $tag, 1 ) : $tag;

		return self::validStableVersion( $version ) ? $version : null;
	}
}

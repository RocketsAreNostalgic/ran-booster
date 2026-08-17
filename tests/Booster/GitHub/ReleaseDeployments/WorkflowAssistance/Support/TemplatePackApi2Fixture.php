<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support;

use ZipArchive;

final class TemplatePackApi2Fixture {

	public const REPOSITORY    = 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates';
	public const REPOSITORY_ID = '1322743261';
	public const RELEASE_ID    = 41;
	public const ASSET_ID      = 73;
	public const COMMIT        = '0123456789abcdef0123456789abcdef01234567';
	public const ASSET_NAME    = 'ran-booster-release-bootstrap-templates.zip';

	/** @return array<string, string> */
	public static function templates(): array {
		return array(
			'templates/shared/release-please.yml.tmpl' => "branch={{RAN_DEFAULT_BRANCH}}\nslug={{RAN_PACKAGE_SLUG}}\nliteral=\$(printf inert)\n",
			'templates/profiles/wordpress-plugin/release-please-config.json.tmpl' => '{"base":"{{RAN_BASE_SHA}}","extra-files":{{RAN_EXTRA_FILES_JSON}},"slug":"{{RAN_PACKAGE_SLUG}}"}' . "\n",
			'templates/profiles/wordpress-theme/release-please-config.json.tmpl' => '{"base":"{{RAN_BASE_SHA}}","extra-files":{{RAN_EXTRA_FILES_JSON}},"slug":"{{RAN_PACKAGE_SLUG}}"}' . "\n",
			'templates/shared/build-release.sh.tmpl'   => "slug={{RAN_PACKAGE_SLUG}}\ntype={{RAN_PACKAGE_TYPE}}\nheader={{RAN_HEADER_PATH}}\n",
			'templates/shared/verify-release.sh.tmpl'  => "slug={{RAN_PACKAGE_SLUG}}\ntype={{RAN_PACKAGE_TYPE}}\nheader={{RAN_HEADER_PATH}}\nupdate_uri={{RAN_UPDATE_URI}}\n",
			'templates/shared/upload-release-assets.sh.tmpl' => "printf '%s\\n' 'fixed uploader bytes'\n",
		);
	}

	/** @return array<string, mixed> */
	public static function manifest( int $consumerApi = 2, string $version = '1.2.3' ): array {
		$templates     = self::templates();
		$sharedEntries = array(
			'release-workflow'             => self::entry(
				'templates/shared/release-please.yml.tmpl',
				$templates['templates/shared/release-please.yml.tmpl'],
				array(
					'DEFAULT_BRANCH' => 'branch',
					'PACKAGE_SLUG'   => 'slug',
				)
			),
			'build-release-script'         => self::entry(
				'templates/shared/build-release.sh.tmpl',
				$templates['templates/shared/build-release.sh.tmpl'],
				array(
					'HEADER_PATH'  => 'path',
					'PACKAGE_SLUG' => 'slug',
					'PACKAGE_TYPE' => 'package_type',
				)
			),
			'verify-release-script'        => self::entry(
				'templates/shared/verify-release.sh.tmpl',
				$templates['templates/shared/verify-release.sh.tmpl'],
				array(
					'HEADER_PATH'  => 'path',
					'PACKAGE_SLUG' => 'slug',
					'PACKAGE_TYPE' => 'package_type',
					'UPDATE_URI'   => 'github_uri',
				)
			),
			'upload-release-assets-script' => self::entry(
				'templates/shared/upload-release-assets.sh.tmpl',
				$templates['templates/shared/upload-release-assets.sh.tmpl'],
				array()
			),
		);
		$profiles      = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$configPath = 'templates/profiles/wordpress-' . $type . '/release-please-config.json.tmpl';
			$entries    = array(
				'release-workflow'             => $sharedEntries['release-workflow'],
				'release-please-config'        => self::entry(
					$configPath,
					$templates[ $configPath ],
					array(
						'BASE_SHA'         => 'sha',
						'EXTRA_FILES_JSON' => 'json_fragment',
						'PACKAGE_SLUG'     => 'slug',
					)
				),
				'build-release-script'         => $sharedEntries['build-release-script'],
				'verify-release-script'        => $sharedEntries['verify-release-script'],
				'upload-release-assets-script' => $sharedEntries['upload-release-assets-script'],
			);
			$profiles[ 'source-ready-wordpress-' . $type . '/2' ] = array(
				'profile_version' => 1,
				'entries'         => $entries,
			);
		}

		return array(
			'schema_version' => 1,
			'consumer_api'   => $consumerApi,
			'pack_version'   => $version,
			'repository'     => array(
				'name' => self::REPOSITORY,
				'id'   => self::REPOSITORY_ID,
			),
			'release'        => array(
				'id'     => self::RELEASE_ID,
				'tag'    => 'v' . $version,
				'commit' => self::COMMIT,
			),
			'profiles'       => $profiles,
		);
	}

	/**
	 * @param array<string, mixed>|null $manifest
	 * @param array<string, string>     $memberOverrides
	 * @param array<string, string>     $extra
	 */
	public static function archive(
		?array $manifest = null,
		array $memberOverrides = array(),
		array $extra = array(),
		string $modeMember = '',
		int $mode = 0100644,
		array $omit = array()
	): string {
		$manifest ??= self::manifest();
		$path       = tempnam( sys_get_temp_dir(), 'ran-pack-fixture-' );
		if ( false === $path ) {
			throw new \RuntimeException( 'Unable to create template-pack fixture.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Unable to open template-pack fixture.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture requires throwing deterministic JSON encoding.
		$manifestBytes = (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		$members       = array_merge(
			array( 'template-pack.json' => $manifestBytes ),
			self::templates(),
			$memberOverrides,
			$extra
		);
		foreach ( $omit as $name ) {
			unset( $members[ $name ] );
		}
		foreach ( $members as $name => $content ) {
			$zip->addFromString( $name, $content );
			$zip->setExternalAttributesName(
				$name,
				ZipArchive::OPSYS_UNIX,
				( $name === $modeMember ? $mode : 0100644 ) << 16
			);
		}
		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the exact local test temporary archive.
		$bytes = file_get_contents( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the exact test temporary file.
		unlink( $path );
		if ( ! is_string( $bytes ) ) {
			throw new \RuntimeException( 'Unable to read template-pack fixture.' );
		}

		return $bytes;
	}

	/** @return array<string, mixed> */
	public static function identity( string $archive, string $version = '1.2.3' ): array {
		return array(
			'repository_name'    => self::REPOSITORY,
			'repository_id'      => self::REPOSITORY_ID,
			'release_id'         => self::RELEASE_ID,
			'release_tag'        => 'v' . $version,
			'release_commit'     => self::COMMIT,
			'release_target'     => self::COMMIT,
			'tag_target'         => self::COMMIT,
			'release_draft'      => false,
			'release_prerelease' => false,
			'release_immutable'  => true,
			'asset_count'        => 1,
			'asset_id'           => self::ASSET_ID,
			'asset_name'         => self::ASSET_NAME,
			'asset_state'        => 'uploaded',
			'asset_content_type' => 'application/zip',
			'asset_size'         => strlen( $archive ),
			'asset_digest'       => 'sha256:' . hash( 'sha256', $archive ),
			'asset_sha256'       => hash( 'sha256', $archive ),
		);
	}

	/** @param array<string, string> $placeholders @return array<string, mixed> */
	private static function entry( string $path, string $content, array $placeholders ): array {
		return array(
			'path'         => $path,
			'size'         => strlen( $content ),
			'sha256'       => hash( 'sha256', $content ),
			'placeholders' => $placeholders,
		);
	}
}

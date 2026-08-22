<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\Support;

require_once __DIR__ . '/NeutralReleaseUpdaterWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/../ran-wp-release-updater/runtime.php';

final class NeutralReleaseUpdaterFixtures {
	public static function reset(): void {
		$GLOBALS['ran_booster_release_requests']   = array();
		$GLOBALS['ran_booster_release_responses']  = array();
		$GLOBALS['ran_booster_release_temp_paths'] = array();
		$GLOBALS['wp_version']                     = '6.8.0'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deterministic updater runtime fixture.
	}

	public static function cleanup(): void {
		foreach ( $GLOBALS['ran_booster_release_temp_paths'] ?? array() as $path ) {
			if ( is_string( $path ) && ( is_file( $path ) || is_link( $path ) ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only fallback cleanup.
			}
		}
		self::reset();
	}

	/** @param list<array<string, mixed>|\WP_Error> $responses */
	public static function queue( array $responses ): void {
		$GLOBALS['ran_booster_release_responses'] = $responses;
	}

	/** @return list<array{0:string,1:array<string,mixed>}> */
	public static function requests(): array {
		return $GLOBALS['ran_booster_release_requests'] ?? array();
	}

	/** @param list<array<string, mixed>> $releases */
	public static function listing( array $releases ): array {
		return self::response( 200, $releases );
	}

	/** @return list<array<string, mixed>> */
	public static function proof( string $type = 'plugin', string $locator = 'owner/example', string $tag = 'v1.2.3', string $version = '1.2.3' ): array {
		$archive = self::archive( $type, $locator, $version );
		$release = self::release( $locator, $tag, $version, $archive );

		return array(
			self::response( 200, array( 'id' => 123456789 ) ),
			self::response( 200, $release ),
			self::response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			self::response( 200, array( 'id' => 123456789 ) ),
			self::response( 200, null, array(), $archive ),
			self::response( 200, array( 'id' => 123456789 ) ),
		);
	}

	/** @return array<string, mixed> */
	public static function listedRelease( string $locator = 'owner/example', string $tag = 'v1.2.3', bool $prerelease = false, int $id = 42 ): array {
		return array(
			'assets'       => array( array( 'name' => 'example.zip' ) ),
			'draft'        => false,
			'html_url'     => 'https://github.com/' . $locator . '/releases/tag/' . $tag,
			'id'           => $id,
			'immutable'    => true,
			'prerelease'   => $prerelease,
			'published_at' => '2026-08-22T10:00:00Z',
			'tag_name'     => $tag,
		);
	}

	/** @return array<string, mixed> */
	public static function response( int $code, mixed $json, array $headers = array(), ?string $file = null ): array {
		$response = array(
			'body'     => null === $json ? '' : json_encode( $json, JSON_THROW_ON_ERROR ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in this bounded fixture.
			'headers'  => $headers,
			'response' => array( 'code' => $code ),
		);
		if ( null !== $file ) {
			$response['file'] = $file;
		}

		return $response;
	}

	private static function archive( string $type, string $locator, string $version ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-neutral-archive-' );
		if ( false === $path ) {
			throw new \RuntimeException( 'The neutral updater ZIP fixture could not be created.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'The neutral updater ZIP fixture could not be opened.' );
		}
		$header = ( 'theme' === $type ? 'Theme Name' : 'Plugin Name' ) . ": Example\n"
			. "Version: {$version}\nUpdate URI: https://github.com/{$locator}\nRequires PHP: 8.2\nRequires at least: 6.8\n";
		$zip->addFromString( 'example/' . ( 'theme' === $type ? 'style.css' : 'example.php' ), $header );
		$zip->close();
		$archive = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local ZIP fixture.
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only ZIP fixture cleanup.
		if ( ! is_string( $archive ) ) {
			throw new \RuntimeException( 'The neutral updater ZIP fixture could not be read.' );
		}

		return $archive;
	}

	/** @return array<string, mixed> */
	private static function release( string $locator, string $tag, string $version, string $archive ): array {
		$release           = self::listedRelease( $locator, $tag, str_contains( $version, '-' ) );
		$release['assets'] = array(
			array(
				'digest' => 'sha256:' . hash( 'sha256', $archive ),
				'id'     => 8,
				'name'   => 'example.zip',
				'size'   => strlen( $archive ),
				'state'  => 'uploaded',
			),
		);

		return $release;
	}
}

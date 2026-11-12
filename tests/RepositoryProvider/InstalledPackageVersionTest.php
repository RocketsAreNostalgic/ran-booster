<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InstalledPackageVersion;

final class InstalledPackageVersionTest extends TestCase {
	public function test_composer_metadata_reports_the_installed_package_version(): void {
		$expected = \Composer\InstalledVersions::getPrettyVersion( 'ran/booster-github-provider' );
		self::assertNotNull( $expected );
		self::assertSame( $expected, ( new InstalledPackageVersion() )->for_package( 'ran/booster-github-provider' ) );
	}

	public function test_unknown_package_is_unavailable(): void {
		self::assertNull( ( new InstalledPackageVersion() )->for_package( 'ran/not-installed-package' ) );
	}

	public function test_a_build_record_supplies_the_version_when_composer_metadata_is_absent(): void {
		$path = $this->record( '{"schema":"ran-booster-built-with","schema_version":1,"packages":{"ran/recorded-only":"v1.0.0-beta.21"}}' );

		self::assertSame( 'v1.0.0-beta.21', ( new InstalledPackageVersion( null, $path ) )->for_package( 'ran/recorded-only' ) );
		self::assertNull( ( new InstalledPackageVersion( null, $path ) )->for_package( 'ran/not-recorded' ) );
	}

	public function test_composer_metadata_wins_over_the_build_record(): void {
		$expected = \Composer\InstalledVersions::getPrettyVersion( 'ran/booster-github-provider' );
		self::assertNotNull( $expected );
		$path = $this->record( '{"schema":"ran-booster-built-with","schema_version":1,"packages":{"ran/booster-github-provider":"v0.0.1"}}' );

		self::assertSame( $expected, ( new InstalledPackageVersion( null, $path ) )->for_package( 'ran/booster-github-provider' ) );
	}

	/** @dataProvider unusable_records */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unusable_records' )]
	public function test_an_unusable_build_record_is_unavailable( string $contents ): void {
		self::assertNull( ( new InstalledPackageVersion( null, $this->record( $contents ) ) )->for_package( 'ran/recorded-only' ) );
	}

	/** @return array<string, array{string}> */
	public static function unusable_records(): array {
		return array(
			'malformed json'         => array( '{not json' ),
			'wrong schema'           => array( '{"schema":"other","schema_version":1,"packages":{"ran/recorded-only":"v1.0.0"}}' ),
			'wrong schema version'   => array( '{"schema":"ran-booster-built-with","schema_version":2,"packages":{"ran/recorded-only":"v1.0.0"}}' ),
			'packages not an object' => array( '{"schema":"ran-booster-built-with","schema_version":1,"packages":"v1.0.0"}' ),
			'version not a string'   => array( '{"schema":"ran-booster-built-with","schema_version":1,"packages":{"ran/recorded-only":1}}' ),
			'unsafe version text'    => array( '{"schema":"ran-booster-built-with","schema_version":1,"packages":{"ran/recorded-only":"1.0 <script>"}}' ),
			'oversized record'       => array( '{"schema":"ran-booster-built-with","schema_version":1,"packages":{"ran/recorded-only":"v1.0.0"},"pad":"' . str_repeat( 'x', 5000 ) . '"}' ),
		);
	}

	public function test_a_missing_or_symbolic_build_record_is_unavailable(): void {
		$target = $this->record( '{"schema":"ran-booster-built-with","schema_version":1,"packages":{"ran/recorded-only":"v1.0.0"}}' );
		$link   = $target . '.link';
		symlink( $target, $link );
		$this->records[] = $link;

		self::assertNull( ( new InstalledPackageVersion( null, $target . '.absent' ) )->for_package( 'ran/recorded-only' ) );
		self::assertNull( ( new InstalledPackageVersion( null, $link ) )->for_package( 'ran/recorded-only' ) );
	}

	private function record( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-built-with-' );
		self::assertIsString( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture file for a reader that is independent of WordPress APIs.
		file_put_contents( $path, $contents );
		$this->records[] = $path;

		return $path;
	}

	/** @var list<string> */
	private array $records = array();

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( $this->records as $path ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary test file cleanup.
		}
		parent::tearDown();
	}

	public function test_failing_or_malformed_metadata_is_unavailable(): void {
		self::assertNull( ( new InstalledPackageVersion( static fn (): ?string => throw new \RuntimeException( 'boom' ) ) )->for_package( 'a/b' ) );
		self::assertNull( ( new InstalledPackageVersion( static fn (): string => str_repeat( '1', 81 ) ) )->for_package( 'a/b' ) );
		self::assertNull( ( new InstalledPackageVersion( static fn (): string => '' ) )->for_package( 'a/b' ) );
		self::assertSame( 'v1.2.3', ( new InstalledPackageVersion( static fn (): string => 'v1.2.3' ) )->for_package( 'a/b' ) );
	}
}

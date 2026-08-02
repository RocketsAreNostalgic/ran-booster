<?php

declare(strict_types=1);

namespace Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;

#[CoversClass( PackageBlueprint::class )]
#[CoversClass( BlueprintPackage::class )]
#[CoversClass( BlueprintCredential::class )]
final class PackageBlueprintTest extends TestCase {

	public function testCanonicalJsonUsesTheExactV1SchemaAndSortsPackages(): void {
		$blueprint = new PackageBlueprint( array( $this->package( 'theme', 'example-theme', 'Example Theme' ), $this->package() ) );

		$json = $blueprint->canonicalJson();

		self::assertSame( $json, PackageBlueprint::fromJson( $json )->canonicalJson() );
		self::assertSame( array( 'format', 'version', 'packages', 'credentials' ), array_keys( json_decode( $json, true, flags: JSON_THROW_ON_ERROR ) ) );
		self::assertStringContainsString( '"credentials":[]', $json );
		self::assertLessThan( strpos( $json, '"type":"theme"' ), strpos( $json, '"type":"plugin"' ) );
		self::assertStringContainsString( '"display_name":"Example Plugin"', $json );
		self::assertStringNotContainsString( 'created_at', $json );
		self::assertStringNotContainsString( 'destination_slug', $json );
		self::assertStringNotContainsString( 'private', $json );
		self::assertStringNotContainsString( 'token-canary', $json );
	}

	public function testUnknownVersionsFailBeforeNestedPackageOrCredentialParsing(): void {
		$records = array(
			array(
				'packages'    => array( array( 'invalid_package' => true ) ),
				'credentials' => array(),
				'class'       => BlueprintPackage::class,
			),
			array(
				'packages'    => array(),
				'credentials' => array( array( 'invalid_credential' => true ) ),
				'class'       => BlueprintCredential::class,
			),
		);

		foreach ( $records as $record ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical contract fixture.
			$json = json_encode(
				array(
					'format'      => PackageBlueprint::FORMAT,
					'version'     => PackageBlueprint::VERSION + 1,
					'packages'    => $record['packages'],
					'credentials' => $record['credentials'],
				),
				JSON_THROW_ON_ERROR
			);

			try {
				PackageBlueprint::fromJson( $json );
				self::fail( 'Expected an unknown Blueprint version to be rejected.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability blueprint is invalid.', $exception->getMessage() );
				self::assertNotContains( $record['class'], array_column( $exception->getTrace(), 'class' ) );
			}
		}
	}

	public function testCredentialsAreCanonicalAndContainNoSourceId(): void {
		$credential = new BlueprintCredential(
			'gh',
			'Team token',
			'fine-grained',
			array(
				'scope' => 'repo',
				'owner' => 'team',
			),
			'secret-canary-value',
			array( $this->identity() )
		);
		$json       = ( new PackageBlueprint( array( $this->package() ), array( $credential ) ) )->canonicalJson();

		self::assertSame( $json, PackageBlueprint::fromJson( $json )->canonicalJson() );
		self::assertStringContainsString( '"configuration":{"owner":"team","scope":"repo"}', $json );
		self::assertStringContainsString( '"secret":"secret-canary-value"', $json );
		self::assertStringNotContainsString( 'source_id', $json );
	}

	public function testEmptyAndStructuredCredentialConfigurationRoundTrips(): void {
		foreach ( array(
			array(),
			array(
				'options' => array(
					'retry'  => 2,
					'scopes' => array( 'read', 'archive' ),
				),
			),
		) as $configuration ) {
			$json = ( new PackageBlueprint(
				array( $this->package() ),
				array( new BlueprintCredential( 'gh', 'Token', 'custom', $configuration, 'secret', array( $this->identity() ) ) )
			) )->canonicalJson();
			self::assertSame( $json, PackageBlueprint::fromJson( $json )->canonicalJson() );
		}
		$emptyJson = ( new PackageBlueprint(
			array( $this->package() ),
			array( new BlueprintCredential( 'gh', 'Token', 'custom', array(), 'secret', array( $this->identity() ) ) )
		) )->canonicalJson();
		self::assertStringContainsString( '"configuration":{}', $emptyJson );
	}

	public function testCredentialAssociationsMustReferenceOnePackageOfTheSameProvider(): void {
		foreach ( array(
			new BlueprintCredential( 'bb', 'Token', 'app-password', array(), 'secret', array( $this->identity() ) ),
			new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity( identifier: 'missing/missing.php' ) ) ),
		) as $credential ) {
			try {
				new PackageBlueprint( array( $this->package() ), array( $credential ) );
				self::fail( 'Expected an invalid credential association.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}

		$this->expectException( InvalidArgumentException::class );
		new PackageBlueprint(
			array( $this->package() ),
			array(
				new BlueprintCredential( 'gh', 'First', 'classic', array(), 'secret-one', array( $this->identity() ) ),
				new BlueprintCredential( 'gh', 'Second', 'classic', array(), 'secret-two', array( $this->identity() ) ),
			)
		);
	}

	public function testEquivalentCredentialMaterialCannotBeSplitAcrossRecords(): void {
		$this->expectException( InvalidArgumentException::class );
		new PackageBlueprint(
			array( $this->package(), $this->package( 'theme', 'example-theme', 'Example Theme' ) ),
			array(
				new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity() ) ),
				new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity( 'theme', 'example-theme' ) ) ),
			)
		);
	}

	public function testRejectsInvalidCredentialRecordsAndCredentialLimit(): void {
		$valid = new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity() ) );
		try {
			new PackageBlueprint( array(), array_fill( 0, PackageBlueprint::MAX_CREDENTIALS + 1, $valid ) );
			self::fail( 'Expected the credential limit to be enforced.' );
		} catch ( InvalidArgumentException ) {
			self::addToAssertionCount( 1 );
		}

		foreach ( array( '', "line\nbreak", str_repeat( 'x', 4097 ) ) as $secret ) {
			try {
				new BlueprintCredential( 'gh', 'Token', 'classic', array(), $secret, array( $this->identity() ) );
				self::fail( 'Expected an invalid credential secret.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function testCredentialSecretIsRedactedFromConstructorTrace(): void {
		try {
			new BlueprintCredential( 'gh', 'Token', 'classic', array(), "trace-secret-canary\n", array( $this->identity() ) );
			self::fail( 'Expected an invalid credential secret.' );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of redacted exception arguments.
			self::assertStringNotContainsString( 'trace-secret-canary', var_export( $exception->getTrace(), true ) );
		}
	}

	public function testCredentialSecretIsRedactedFromParserTrace(): void {
		$json = ( new PackageBlueprint(
			array( $this->package() ),
			array( new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'parser-trace-secret-canary', array( $this->identity() ) ) )
		) )->canonicalJson();
		$json = str_replace( '"kind":"classic"', '"kind":""', $json );

		try {
			PackageBlueprint::fromJson( $json );
			self::fail( 'Expected an invalid credential record.' );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of redacted exception arguments.
			self::assertStringNotContainsString( 'parser-trace-secret-canary', var_export( $exception->getTrace(), true ) );
		}
	}

	public function testRejectsUnknownAndSecretAdjacentFields(): void {
		$record                 = $this->package()->toArray();
		$record['access_token'] = 'token-canary';

		$this->expectException( InvalidArgumentException::class );
		BlueprintPackage::fromArray( $record );
	}

	public function testRejectsEveryRemovedV1Field(): void {
		foreach ( array( 'private', 'destination_slug' ) as $legacyField ) {
			$record                 = $this->package()->toArray();
			$record[ $legacyField ] = 'removed';
			try {
				BlueprintPackage::fromArray( $record );
				self::fail( 'Expected a removed V1 field to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}

		$json = ( new PackageBlueprint( array( $this->package() ) ) )->canonicalJson();
		$json = str_replace( '"version":1,', '"version":1,"created_at":"2026-07-20T00:00:00Z",', $json );
		$this->expectException( InvalidArgumentException::class );
		PackageBlueprint::fromJson( $json );
	}

	public function testRejectsInvalidUtf8AndResourceLimits(): void {
		foreach ( array( "\xB1", str_repeat( ' ', PackageBlueprint::MAX_BYTES + 1 ) ) as $invalid ) {
			try {
				PackageBlueprint::fromJson( $invalid );
				self::fail( 'Expected invalid blueprint JSON to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function testRejectsJsonPastTheMaximumNestingDepth(): void {
		$this->expectException( InvalidArgumentException::class );
		PackageBlueprint::fromJson( str_repeat( '{"record":', 17 ) . 'null' . str_repeat( '}', 17 ) );
	}

	public function testRejectsTooManyOrDuplicatePackages(): void {
		try {
			new PackageBlueprint( array_fill( 0, PackageBlueprint::MAX_PACKAGES + 1, $this->package() ) );
			self::fail( 'Expected the package limit to be enforced.' );
		} catch ( InvalidArgumentException ) {
			self::addToAssertionCount( 1 );
		}

		$this->expectException( InvalidArgumentException::class );
		new PackageBlueprint( array( $this->package(), $this->package() ) );
	}

	public function testPackageIdentityIncludesItsType(): void {
		$plugin = $this->package( identifier: 'example.php' );
		$theme  = $this->package( 'theme', 'example.php', 'Example Theme' );
		$result = new PackageBlueprint(
			array( $plugin, $theme ),
			array( new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity( identifier: 'example.php' ) ) ) )
		);

		self::assertCount( 2, $result->packages );
	}

	public function testRejectsConstructedBlueprintWhenCanonicalEncodingExceedsTheByteLimit(): void {
		$packages = array();
		for ( $index = 0; $index < PackageBlueprint::MAX_PACKAGES; ++$index ) {
			$packages[] = new BlueprintPackage(
				'plugin',
				'example-' . $index . '.php',
				str_repeat( '\\', 191 ),
				'gh',
				str_repeat( '\\', 191 ),
				str_repeat( '\\', 512 ),
				str_repeat( '\\', 255 ),
				null
			);
		}

		$this->expectException( InvalidArgumentException::class );
		( new PackageBlueprint( $packages ) )->canonicalJson();
	}

	public function testRejectsUrlLocatorsThatCanEmbedCredentials(): void {
		$locators = array(
			'https://token-canary@example.test/owner/repository',
			'https://example.test/owner/repository?token=token-canary',
			'https://example.test/owner/repository#token-canary',
		);
		foreach ( $locators as $locator ) {
			$record               = $this->package()->toArray();
			$record['repository'] = $locator;
			try {
				BlueprintPackage::fromArray( $record );
				self::fail( 'Expected a potentially credential-bearing locator to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function testRejectsDuplicateOrNonCanonicalJsonObjectKeys(): void {
		$json    = ( new PackageBlueprint( array( $this->package() ) ) )->canonicalJson();
		$invalid = array(
			str_replace( '"format":"ran-booster-package-blueprint"', '"format":"invalid","format":"ran-booster-package-blueprint"', $json ),
			str_replace( '{"format":"ran-booster-package-blueprint","version":1', '{"version":1,"format":"ran-booster-package-blueprint"', $json ),
		);
		foreach ( $invalid as $candidate ) {
			try {
				PackageBlueprint::fromJson( $candidate );
				self::fail( 'Expected noncanonical JSON to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function testDisplayNameIsBoundedInformationNotManagementIdentity(): void {
		$left  = $this->package( displayName: 'Old Label' );
		$right = $this->package( displayName: 'New Label' );

		self::assertTrue( $left->sameManagementAs( $right ) );

		foreach ( array( '', ' padded ', "line\nbreak", "\xB1", str_repeat( 'x', 192 ) ) as $displayName ) {
			try {
				$this->package( displayName: $displayName );
				self::fail( 'Expected an invalid display name to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function testEveryAuthoritativeFieldAffectsManagementEquality(): void {
		$package = $this->package();
		$changed = array(
			$this->package( identifier: 'other/other.php' ),
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example Plugin', 'bb', '123', 'owner/repository', 'main', null ),
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example Plugin', 'gh', '456', 'owner/repository', 'main', null ),
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example Plugin', 'gh', '123', 'owner/other', 'main', null ),
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example Plugin', 'gh', '123', 'owner/repository', 'develop', null ),
			new BlueprintPackage( 'plugin', 'example/example.php', 'Example Plugin', 'gh', '123', 'owner/repository', 'main', 'packages/example' ),
		);

		foreach ( $changed as $candidate ) {
			self::assertFalse( $package->sameManagementAs( $candidate ) );
		}

		$plugin = new BlueprintPackage( 'plugin', 'example.php', 'Example', 'gh', '123', 'owner/repository', 'main', null );
		$theme  = new BlueprintPackage( 'theme', 'example.php', 'Example', 'gh', '123', 'owner/repository', 'main', null );
		self::assertFalse( $plugin->sameManagementAs( $theme ) );
	}

	public function testRejectsNonCanonicalSubdirectory(): void {
		foreach ( array( ' packages/example ', str_repeat( 'x', 256 ) ) as $subdirectory ) {
			try {
				new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', '123', 'owner/repository', 'main', $subdirectory );
				self::fail( 'Expected an invalid subdirectory to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	private function package( string $type = 'plugin', string $identifier = 'example/example.php', string $displayName = 'Example Plugin' ): BlueprintPackage {
		return new BlueprintPackage( $type, $identifier, $displayName, 'gh', '123', 'owner/repository', 'main', null );
	}

	/** @return array{type:string,identifier:string} */
	private function identity( string $type = 'plugin', string $identifier = 'example/example.php' ): array {
		return array(
			'type'       => $type,
			'identifier' => $identifier,
		);
	}
}

<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\Secrets\SecretsFile;
use RuntimeException;

#[CoversClass( PackageBlueprint::class )]
#[CoversClass( BlueprintPackage::class )]
#[CoversClass( BlueprintCredential::class )]
final class PackageBlueprintTest extends TestCase {

	public function test_canonical_json_uses_the_exact_v2_schema_and_sorts_packages(): void {
		$blueprint = new PackageBlueprint( array( $this->package( 'theme', 'example-theme', 'Example Theme' ), $this->package() ) );

		$json = $blueprint->canonical_json();

		self::assertSame( $json, PackageBlueprint::from_json( $json )->canonical_json() );
		self::assertSame( array( 'credentials', 'format', 'packages', 'version' ), array_keys( json_decode( $json, true, flags: JSON_THROW_ON_ERROR ) ) );
		self::assertStringContainsString( '"credentials":[]', $json );
		self::assertLessThan( strpos( $json, '"type":"theme"' ), strpos( $json, '"type":"plugin"' ) );
		self::assertStringContainsString( '"display_name":"Example Plugin"', $json );
		self::assertStringNotContainsString( 'created_at', $json );
		self::assertStringNotContainsString( 'destination_slug', $json );
		self::assertStringNotContainsString( 'private', $json );
		self::assertStringNotContainsString( 'token-canary', $json );
	}

	public function test_unknown_versions_fail_before_nested_package_or_credential_parsing(): void {
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
				PackageBlueprint::from_json( $json );
				self::fail( 'Expected an unknown Blueprint version to be rejected.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability blueprint is invalid.', $exception->getMessage() );
				self::assertNotContains( $record['class'], array_column( $exception->getTrace(), 'class' ) );
			}
		}
	}

	public function test_credentials_are_canonical_and_contain_no_source_id(): void {
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
		$json       = ( new PackageBlueprint( array( $this->package() ), array( $credential ) ) )->canonical_json();

		self::assertSame( $json, PackageBlueprint::from_json( $json )->canonical_json() );
		self::assertStringContainsString( '"configuration":{"owner":"team","scope":"repo"}', $json );
		self::assertStringContainsString( '"secret":"secret-canary-value"', $json );
		self::assertStringNotContainsString( 'source_id', $json );
	}

	public function test_credential_keeps_the_secret_private_but_projects_exact_portable_material(): void {
		$secret     = 'private-storage-secret-marker';
		$credential = new BlueprintCredential(
			'gh',
			'Team token',
			'fine-grained',
			array(
				'zeta'  => true,
				'alpha' => array(
					'second' => 2,
					'first'  => 1,
				),
			),
			$secret,
			array(
				$this->identity( 'theme', 'example-theme' ),
				$this->identity(),
			)
		);

		self::assertSame( $secret, $credential->reveal_secret() );
		self::assertSame(
			array(
				'provider'      => 'gh',
				'label'         => 'Team token',
				'kind'          => 'fine-grained',
				'configuration' => array(
					'alpha' => array(
						'first'  => 1,
						'second' => 2,
					),
					'zeta'  => true,
				),
				'secret'        => $secret,
				'packages'      => array(
					$this->identity(),
					$this->identity( 'theme', 'example-theme' ),
				),
			),
			$credential->to_array()
		);

		$empty = new BlueprintCredential( 'gh', 'Empty', 'custom', array(), $secret, array( $this->identity() ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Assert the portable empty-configuration JSON object shape.
		self::assertSame( '{}', json_encode( $empty->to_array()['configuration'], JSON_THROW_ON_ERROR ) );
	}

	public function test_nested_credential_secret_is_absent_from_default_object_inspection(): void {
		$marker     = 'private-storage-secret-marker';
		$credential = new BlueprintCredential( 'gh', 'Token', 'classic', array(), $marker, array( $this->identity() ) );
		$blueprint  = new PackageBlueprint(
			array( $this->package() ),
			array( $credential )
		);

		foreach ( array( $credential, $blueprint ) as $value ) {
			foreach ( self::inspection_outputs( $value ) as $output ) {
				self::assertStringNotContainsString( $marker, $output );
			}
		}
	}

	public function test_raw_secret_array_remains_detectable_by_each_object_inspection_control(): void {
		$marker = 'private-storage-secret-marker';
		$raw    = array( 'secret' => $marker );

		foreach ( self::inspection_outputs( $raw ) as $output ) {
			self::assertStringContainsString( $marker, $output );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Positive control proves raw PHP serialization retains ordinary array strings.
		self::assertStringContainsString( $marker, serialize( $raw ) );
	}

	public function test_unannotated_forwarding_trace_and_exception_rendering_redact_credential_objects(): void {
		$marker     = 'unannotated-forwarding-secret-marker';
		$credential = new BlueprintCredential( 'gh', 'Token', 'classic', array(), $marker, array( $this->identity() ) );
		$blueprint  = new PackageBlueprint( array( $this->package() ), array( $credential ) );
		$previous   = ini_get( 'zend.exception_ignore_args' );
		self::assertIsString( $previous );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only setting forces unannotated forwarding arguments into the trace.
		self::assertNotFalse( ini_set( 'zend.exception_ignore_args', '0' ) );

		try {
			try {
				self::throw_from_unannotated_forwarding_frame( $credential, $blueprint );
				self::fail( 'Expected the synthetic forwarding frame to throw.' );
			} catch ( RuntimeException $exception ) {
				$trace = $exception->getTrace();
				self::assertSame( $credential, $trace[0]['args'][0] ?? null );
				self::assertSame( $blueprint, $trace[0]['args'][1] ?? null );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only raw trace rendering verifies private sensitive values remain masked.
				self::assertStringNotContainsString( $marker, var_export( $trace, true ) );
				self::assertStringNotContainsString( $marker, (string) $exception );
			}
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the test process setting after the forwarding-trace assertion.
			ini_set( 'zend.exception_ignore_args', $previous );
		}

		self::assertSame( $previous, ini_get( 'zend.exception_ignore_args' ) );
	}

	public function test_credential_bearing_blueprint_refuses_php_serialization_without_secret_leakage(): void {
		$marker    = 'private-storage-secret-marker';
		$blueprint = new PackageBlueprint(
			array( $this->package() ),
			array( new BlueprintCredential( 'gh', 'Token', 'classic', array(), $marker, array( $this->identity() ) ) )
		);
		$previous  = ini_get( 'zend.exception_ignore_args' );
		self::assertIsString( $previous );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only setting forces trace arguments on for the redaction assertion.
		self::assertNotFalse( ini_set( 'zend.exception_ignore_args', '0' ) );

		try {
			try {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Assert native serialization refuses the private sensitive value.
				serialize( $blueprint );
				self::fail( 'A credential-bearing blueprint must not be PHP serializable.' );
			} catch ( \Throwable $exception ) {
				self::assertStringContainsString( 'Serialization', $exception->getMessage() );
				self::assertStringNotContainsString( $marker, $exception->getMessage() );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection proves exception arguments stay redacted with arguments enabled.
				self::assertStringNotContainsString( $marker, var_export( $exception->getTrace(), true ) );
			}
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the test process setting after the redaction assertion.
			ini_set( 'zend.exception_ignore_args', $previous );
		}

		self::assertSame( $previous, ini_get( 'zend.exception_ignore_args' ) );
	}

	public function test_empty_and_structured_credential_configuration_round_trips(): void {
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
			) )->canonical_json();
			self::assertSame( $json, PackageBlueprint::from_json( $json )->canonical_json() );
		}
		$empty_json = ( new PackageBlueprint(
			array( $this->package() ),
			array( new BlueprintCredential( 'gh', 'Token', 'custom', array(), 'secret', array( $this->identity() ) ) )
		) )->canonical_json();
		self::assertStringContainsString( '"configuration":{}', $empty_json );
	}

	public function test_credential_associations_must_reference_one_package_of_the_same_provider(): void {
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

	public function test_equivalent_credential_material_cannot_be_split_across_records(): void {
		$this->expectException( InvalidArgumentException::class );
		new PackageBlueprint(
			array( $this->package(), $this->package( 'theme', 'example-theme', 'Example Theme' ) ),
			array(
				new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity() ) ),
				new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity( 'theme', 'example-theme' ) ) ),
			)
		);
	}

	public function test_rejects_invalid_credential_records_and_credential_limit(): void {
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

	public function test_credential_secret_is_redacted_from_constructor_trace(): void {
		$previous = ini_get( 'zend.exception_ignore_args' );
		self::assertIsString( $previous );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only setting forces trace arguments on for the redaction assertion.
		self::assertNotFalse( ini_set( 'zend.exception_ignore_args', '0' ) );

		try {
			try {
				new BlueprintCredential( 'gh', 'Token', 'classic', array(), "trace-secret-canary\n", array( $this->identity() ) );
				self::fail( 'Expected an invalid credential secret.' );
			} catch ( InvalidArgumentException $exception ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of redacted exception arguments with arguments enabled.
				self::assertStringNotContainsString( 'trace-secret-canary', var_export( $exception->getTrace(), true ) );
			}
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the test process setting after the redaction assertion.
			ini_set( 'zend.exception_ignore_args', $previous );
		}

		self::assertSame( $previous, ini_get( 'zend.exception_ignore_args' ) );
	}

	public function test_credential_secret_is_redacted_from_parser_trace(): void {
		$json = ( new PackageBlueprint(
			array( $this->package() ),
			array( new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'parser-trace-secret-canary', array( $this->identity() ) ) )
		) )->canonical_json();
		$json = str_replace( '"kind":"classic"', '"kind":""', $json );

		$previous = ini_get( 'zend.exception_ignore_args' );
		self::assertIsString( $previous );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only setting forces trace arguments on for the redaction assertion.
		self::assertNotFalse( ini_set( 'zend.exception_ignore_args', '0' ) );

		try {
			try {
				PackageBlueprint::from_json( $json );
				self::fail( 'Expected an invalid credential record.' );
			} catch ( InvalidArgumentException $exception ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of redacted exception arguments with arguments enabled.
				self::assertStringNotContainsString( 'parser-trace-secret-canary', var_export( $exception->getTrace(), true ) );
			}
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the test process setting after the redaction assertion.
			ini_set( 'zend.exception_ignore_args', $previous );
		}

		self::assertSame( $previous, ini_get( 'zend.exception_ignore_args' ) );
	}

	public function test_secret_changes_remain_distinct_for_blueprint_membership_and_canonical_identity(): void {
		$known     = new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'first-secret-marker', array( $this->identity() ) );
		$different = new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'second-secret-marker', array( $this->identity() ) );
		$blueprint = new PackageBlueprint( array( $this->package() ), array( $known ) );
		$changed   = new PackageBlueprint( array( $this->package() ), array( $different ) );

		self::assertNotSame( $blueprint->canonical_json(), $changed->canonical_json() );
		self::assertNotSame( $blueprint->artifact_identity(), $changed->artifact_identity() );

		try {
			( new SecretsFile( null, array() ) )->import_credentials_if_absent( $blueprint, $different );
			self::fail( 'A credential with different secret material must not match blueprint membership.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The portability credential is not part of this blueprint.', $exception->getMessage() );
		}
	}

	public function test_rejects_unknown_and_secret_adjacent_fields(): void {
		$record                 = $this->package()->to_array();
		$record['access_token'] = 'token-canary';

		$this->expectException( InvalidArgumentException::class );
		BlueprintPackage::from_array( $record );
	}

	public function test_rejects_every_removed_v1_field(): void {
		foreach ( array( 'private', 'destination_slug' ) as $legacy_field ) {
			$record                  = $this->package()->to_array();
			$record[ $legacy_field ] = 'removed';
			try {
				BlueprintPackage::from_array( $record );
				self::fail( 'Expected a removed V1 field to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}

		$json = ( new PackageBlueprint( array( $this->package() ) ) )->canonical_json();
		$json = str_replace( '"version":2', '"version":2,"created_at":"2026-07-20T00:00:00Z"', $json );
		$this->expectException( InvalidArgumentException::class );
		PackageBlueprint::from_json( $json );
	}

	public function test_rejects_invalid_utf8_and_resource_limits(): void {
		foreach ( array( "\xB1", str_repeat( ' ', PackageBlueprint::MAX_BYTES + 1 ) ) as $invalid ) {
			try {
				PackageBlueprint::from_json( $invalid );
				self::fail( 'Expected invalid blueprint JSON to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_rejects_json_past_the_maximum_nesting_depth(): void {
		$this->expectException( InvalidArgumentException::class );
		PackageBlueprint::from_json( str_repeat( '{"record":', 17 ) . 'null' . str_repeat( '}', 17 ) );
	}

	public function test_rejects_too_many_or_duplicate_packages(): void {
		try {
			new PackageBlueprint( array_fill( 0, PackageBlueprint::MAX_PACKAGES + 1, $this->package() ) );
			self::fail( 'Expected the package limit to be enforced.' );
		} catch ( InvalidArgumentException ) {
			self::addToAssertionCount( 1 );
		}

		$this->expectException( InvalidArgumentException::class );
		new PackageBlueprint( array( $this->package(), $this->package() ) );
	}

	public function test_named_constructor_preserves_validated_properties_and_rejects_foreign_items(): void {
		$package   = $this->package();
		$blueprint = new PackageBlueprint( packages: array( $package ), credentials: array() );
		self::assertSame( array( $package ), $blueprint->packages );
		self::assertSame( array(), $blueprint->credentials );
		foreach ( array( array( array( new \stdClass() ), array() ), array( array( $package ), array( new \stdClass() ) ) ) as [$packages, $credentials] ) {
			try {
				new PackageBlueprint( packages: $packages, credentials: $credentials );
				self::fail( 'Foreign blueprint item was accepted.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_constructor_requires_package_and_credential_lists(): void {
		$package    = $this->package();
		$credential = new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity() ) );
		$blueprint  = new PackageBlueprint( packages: array( $package ), credentials: array( $credential ) );
		self::assertSame( array( $package ), $blueprint->packages );
		self::assertSame( array( $credential ), $blueprint->credentials );
		foreach ( array(
			array( array( 'unexpected' => $package ), array( $credential ) ),
			array( array( 1 => $package ), array( $credential ) ),
			array( array( $package ), array( 'unexpected' => $credential ) ),
			array( array( $package ), array( 1 => $credential ) ),
		) as [$packages, $credentials] ) {
			try {
				new PackageBlueprint( packages: $packages, credentials: $credentials );
				self::fail( 'Non-list blueprint input was accepted.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertSame( 'The portability blueprint is invalid.', $exception->getMessage() );
			}
		}
	}

	public function test_package_identity_includes_its_type(): void {
		$plugin = $this->package( identifier: 'example.php' );
		$theme  = $this->package( 'theme', 'example.php', 'Example Theme' );
		$result = new PackageBlueprint(
			array( $plugin, $theme ),
			array( new BlueprintCredential( 'gh', 'Token', 'classic', array(), 'secret', array( $this->identity( identifier: 'example.php' ) ) ) )
		);

		self::assertCount( 2, $result->packages );
	}

	public function test_rejects_constructed_blueprint_when_canonical_encoding_exceeds_the_byte_limit(): void {
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
		( new PackageBlueprint( $packages ) )->canonical_json();
	}

	public function test_rejects_url_locators_that_can_embed_credentials(): void {
		$locators = array(
			'https://token-canary@example.test/owner/repository',
			'https://example.test/owner/repository?token=token-canary',
			'https://example.test/owner/repository#token-canary',
		);
		foreach ( $locators as $locator ) {
			$record               = $this->package()->to_array();
			$record['repository'] = $locator;
			try {
				BlueprintPackage::from_array( $record );
				self::fail( 'Expected a potentially credential-bearing locator to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_rejects_duplicate_or_non_canonical_json_object_keys(): void {
		$json    = ( new PackageBlueprint( array( $this->package() ) ) )->canonical_json();
		$invalid = array(
			str_replace( '"format":"ran-booster-package-blueprint"', '"format":"invalid","format":"ran-booster-package-blueprint"', $json ),
			str_replace( '{"credentials":[],"format":"ran-booster-package-blueprint"', '{"format":"ran-booster-package-blueprint","credentials":[]', $json ),
		);
		foreach ( $invalid as $candidate ) {
			try {
				PackageBlueprint::from_json( $candidate );
				self::fail( 'Expected noncanonical JSON to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_display_name_is_bounded_information_not_management_identity(): void {
		$left  = $this->package( display_name: 'Old Label' );
		$right = $this->package( display_name: 'New Label' );

		self::assertTrue( $left->same_management_as( $right ) );

		foreach ( array( '', ' padded ', "line\nbreak", "\xB1", str_repeat( 'x', 192 ) ) as $display_name ) {
			try {
				$this->package( display_name: $display_name );
				self::fail( 'Expected an invalid display name to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_every_authoritative_field_affects_management_equality(): void {
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
			self::assertFalse( $package->same_management_as( $candidate ) );
		}

		$plugin = new BlueprintPackage( 'plugin', 'example.php', 'Example', 'gh', '123', 'owner/repository', 'main', null );
		$theme  = new BlueprintPackage( 'theme', 'example.php', 'Example', 'gh', '123', 'owner/repository', 'main', null );
		self::assertFalse( $plugin->same_management_as( $theme ) );
	}

	public function test_rejects_non_canonical_subdirectory(): void {
		foreach ( array( ' packages/example ', str_repeat( 'x', 256 ) ) as $subdirectory ) {
			try {
				new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', '123', 'owner/repository', 'main', $subdirectory );
				self::fail( 'Expected an invalid subdirectory to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_original_v1_bytes_normalize_to_one_v2_branch_model(): void {
		$legacy    = '{"format":"ran-booster-package-blueprint","version":1,"packages":[{"type":"plugin","identifier":"example/example.php","display_name":"Example Plugin","provider":"gh","provider_repository_id":"123","repository":"owner/repository","branch":"main","subdirectory":null}],"credentials":[]}';
		$blueprint = PackageBlueprint::from_json( $legacy );
		self::assertSame( $this->package()->to_array(), $blueprint->packages[0]->to_array() );
		self::assertStringContainsString( '"version":2', $blueprint->canonical_json() );
		self::assertSame( hash( 'sha256', $legacy ), $blueprint->artifact_identity() );
		$exported = PackageBlueprint::from_json( $blueprint->canonical_json() );
		self::assertSame( hash( 'sha256', $blueprint->canonical_json() ), $exported->artifact_identity() );
		self::assertNotSame( $blueprint->artifact_identity(), $exported->artifact_identity() );
		self::assertSame( $exported->artifact_identity(), ( new PackageBlueprint( $blueprint->packages ) )->artifact_identity() );
		foreach ( array(
			str_replace( '"version":1', '"version":2', $legacy ),
			str_replace( '"branch":"main"', '"branch":""', $legacy ),
			str_replace( '"subdirectory":null', '"subdirectory":null,"selection":null', $legacy ),
			str_replace( '"branch":"main","subdirectory":null', '"subdirectory":null,"branch":"main"', $legacy ),
			$legacy . "\n",
		) as $invalid ) {
			try {
				PackageBlueprint::from_json( $invalid );
				self::fail( 'Malformed legacy input was accepted.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_mixed_sources_separate_tracking_selection_and_observations(): void {
		$release                 = $this->release_record();
		$release['requirements'] = array(
			'basis'             => 'selected_package',
			'observed_identity' => array(
				'artifact_sha256' => str_repeat( 'b', 64 ),
				'version'         => '1.2.3',
			),
			'declarations'      => array(
				'requires_php'       => '8.2',
				'requires_wordpress' => null,
				'requires_plugins'   => array( 'example' ),
			),
		);
		$package                 = BlueprintPackage::from_array( $release );
		$blueprint               = new PackageBlueprint( array( $this->package(), $package ) );
		$roundtrip               = PackageBlueprint::from_json( $blueprint->canonical_json() );
		self::assertSame( $blueprint->canonical_json(), $roundtrip->canonical_json() );
		self::assertSame( 'prerelease', $package->release_configuration?->channel() );
		self::assertSame( '', $package->branch );
		unset( $release['selection'], $release['requirements'] );
		self::assertTrue( $package->same_management_as( BlueprintPackage::from_array( $release ) ) );
		$release['source']['channel'] = 'stable';
		self::assertFalse( $package->same_management_as( BlueprintPackage::from_array( $release ) ) );
	}

	public function test_v2_rejects_source_and_selected_identity_contradictions(): void {
		$valid   = $this->release_record();
		$invalid = array();
		foreach ( array(
			array( 'kind' => 'unknown' ),
			array( 'branch' => 'main' ),
			array( 'metadata_file' => 'wrong.php' ),
			array( 'package_root' => 'wrong' ),
			array( 'channel' => 'latest' ),
		) as $change ) {
			$candidate           = $valid;
			$candidate['source'] = array_merge( $candidate['source'], $change );
			$invalid[]           = $candidate;
		}
		foreach ( array( null, array( 'commit' => str_repeat( 'a', 40 ) ), array_merge( $valid['selection'], array( 'artifact_size' => 0 ) ), array_merge( $valid['selection'], array( 'fingerprint' => 'local-authority' ) ) ) as $selection ) {
			$candidate              = $valid;
			$candidate['selection'] = $selection;
			$invalid[]              = $candidate;
		}
		foreach ( array(
			array(
				'basis'             => 'selected_package',
				'observed_identity' => array( 'commit' => str_repeat( 'a', 40 ) ),
				'declarations'      => array(),
			),
			array(
				'basis'             => 'selected_package',
				'observed_identity' => array( 'artifact_sha256' => str_repeat( 'c', 64 ) ),
				'declarations'      => array(),
			),
			array(
				'basis'             => 'installed_headers',
				'observed_identity' => array(),
				'declarations'      => array( 'parent_theme' => 'parent' ),
			),
			array(
				'basis'             => 'installed_headers',
				'observed_identity' => array(),
				'declarations'      => array( 'requires_php' => '>=8.2' ),
			),
			array(
				'basis'             => 'installed_headers',
				'observed_identity' => array(),
				'declarations'      => array( 'requires_plugins' => array( 'same', 'same' ) ),
			),
		) as $requirements ) {
			$candidate                 = $valid;
			$candidate['requirements'] = $requirements;
			$invalid[]                 = $candidate;
		}
		foreach ( $invalid as $candidate ) {
			try {
				BlueprintPackage::from_array( $candidate );
				self::fail( 'Contradictory V2 record was accepted.' );
			} catch ( InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_branch_selection_and_empty_observations_roundtrip_without_claiming_identity(): void {
		$record                 = $this->package()->to_array();
		$record['selection']    = array( 'commit' => str_repeat( 'a', 64 ) );
		$record['requirements'] = array(
			'basis'             => 'installed_headers',
			'observed_identity' => array(),
			'declarations'      => array(),
		);
		$json                   = ( new PackageBlueprint( array( BlueprintPackage::from_array( $record ) ) ) )->canonical_json();
		self::assertStringContainsString( '"declarations":{}', $json );
		self::assertStringContainsString( '"observed_identity":{}', $json );
		self::assertSame( $json, PackageBlueprint::from_json( $json )->canonical_json() );
		$record['requirements']['basis'] = 'selected_package';
		$this->expectException( InvalidArgumentException::class );
		BlueprintPackage::from_array( $record );
	}

	/** @return array<string, mixed> */
	private function release_record(): array {
		$record              = $this->package( identifier: 'release/release.php' )->to_array();
		$record['source']    = array(
			'kind'          => 'release_asset',
			'channel'       => 'prerelease',
			'package_root'  => 'release',
			'metadata_file' => 'release.php',
		);
		$record['selection'] = array(
			'release_id'      => '123',
			'tag'             => 'v1.2.3',
			'version'         => '1.2.3',
			'commit'          => str_repeat( 'a', 40 ),
			'artifact_id'     => '456',
			'artifact_sha256' => str_repeat( 'b', 64 ),
			'artifact_size'   => 100,
		);
		return $record;
	}

	private function package( string $type = 'plugin', string $identifier = 'example/example.php', string $display_name = 'Example Plugin' ): BlueprintPackage {
		return new BlueprintPackage( $type, $identifier, $display_name, 'gh', '123', 'owner/repository', 'main', null );
	}

	/** @return array{type:string,identifier:string} */
	private function identity( string $type = 'plugin', string $identifier = 'example/example.php' ): array {
		return array(
			'type'       => $type,
			'identifier' => $identifier,
		);
	}

	private static function throw_from_unannotated_forwarding_frame( BlueprintCredential $credential, PackageBlueprint $blueprint ): void {
		if ( 'gh' !== $credential->provider || array() === $blueprint->packages ) {
			throw new RuntimeException( 'Synthetic forwarding fixture is invalid.' );
		}
		throw new RuntimeException( 'Synthetic forwarding failure.' );
	}

	/** @return array<string, string> */
	private static function inspection_outputs( mixed $value ): array {
		ob_start();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- Test-only inspection of default object dumping behavior.
		var_dump( $value );
		$dump = ob_get_clean();
		self::assertIsString( $dump );

		return array(
			'var_dump'        => $dump,
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Test-only inspection of default object printing behavior.
			'print_r'         => print_r( $value, true ),
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of default object export behavior.
			'var_export'      => var_export( $value, true ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only inspection of default JSON object projection.
			'json'            => json_encode( $value, JSON_THROW_ON_ERROR ),
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of array casting behavior.
			'array_cast'      => var_export( (array) $value, true ),
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test-only inspection of public-object-variable behavior.
			'get_object_vars' => var_export( get_object_vars( is_object( $value ) ? $value : (object) $value ), true ),
		);
	}
}

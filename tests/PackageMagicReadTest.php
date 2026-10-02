<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\Theme;
use ReflectionClass;
use RuntimeException;

final class PackageMagicReadTest extends TestCase {

	public function test_existing_getter_property_reads_retain_their_values_and_case_insensitivity(): void {
		foreach ( array(
			Plugin::class => 'example/example.php',
			Theme::class  => 'example-theme',
		) as $class => $identifier ) {
			$reflection = new ReflectionClass( $class );
			$package    = $reflection->newInstanceWithoutConstructor();
			$reflection->getProperty( Plugin::class === $class ? 'file' : 'stylesheet' )->setValue( $package, $identifier );
			$reflection->getProperty( 'name' )->setValue( $package, ' Example package ' );
			$reflection->getProperty( 'version' )->setValue( $package, '2.0.0' );
			$repository = new ManagedRepository( 'gh', 'owner/example', 'repository-id', 'release', true, 'credential-one' );
			$package->set_repository( $repository );
			$package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );
			$package->set_source( PackageSource::RELEASE_ASSET, 7 );
			$package->set_deployment_ref( 'v2.0.0' );
			$package->set_installation_slug( 'installation-fallback' );
			$package->set_subdirectory( 'packages/installation' );
			$expected = array(
				'identifier'           => $identifier,
				'displayName'          => 'Example package',
				'version'              => '2.0.0',
				'slug'                 => 'installation',
				'subdirectory'         => 'packages/installation',
				'deploymentPolicy'     => DeploymentPolicy::AUTOMATIC,
				'source'               => PackageSource::RELEASE_ASSET,
				'sourceRevision'       => 7,
				'repository'           => $repository,
				'branch'               => 'release',
				'deploymentRef'        => 'v2.0.0',
				'credentialId'         => 'credential-one',
				'providerCode'         => 'gh',
				'providerRepositoryId' => 'repository-id',
				'private'              => true,
			);
			foreach ( $expected as $property => $value ) {
				foreach ( array( $property, ucfirst( $property ), strtoupper( $property ), strtolower( $property ) ) as $case ) {
					self::assertSame( $value, $package->$case, $class . '::$' . $case );
				}
				$snake = strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $property ) );
				self::assertNull( $package->{'_' . $snake} );
				if ( $snake !== $property ) {
					self::assertNull( $package->{'_' . $property} );
					if ( in_array( $snake, array( 'deployment_policy', 'source_revision', 'deployment_ref' ), true ) ) {
						self::assertSame( $value, $package->$snake );
					} else {
						self::assertNull( $package->$snake );
					}
				}
			}
			self::assertSame( 'installation-fallback', $package->installation_slug );
			self::assertNull( $package->_installation_slug );
			self::assertSame( $identifier, (string) $package );
			self::assertNull( $package->unknown );
			self::assertNull( $package->{''} );
		}
	}

	public function test_renamed_header_backing_fields_use_exact_snake_case_fallbacks(): void {
		foreach ( array(
			Plugin::class => array( 'plugin_uri', 'author_uri', 'text_domain', 'domain_path', 'author_name' ),
			Theme::class  => array( 'theme_uri', 'author_uri', 'text_domain', 'domain_path' ),
		) as $class => $fields ) {
			$reflection = new ReflectionClass( $class );
			$package    = $reflection->newInstanceWithoutConstructor();
			foreach ( $fields as $field ) {
				$value = 'header-' . $field;
				$reflection->getProperty( $field )->setValue( $package, $value );
				self::assertSame( $value, $package->$field );
				$old_field = lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $field ) ) ) );
				self::assertNull( $package->$old_field );
				self::assertNull( $package->{strtoupper( $field )} );
			}
		}
	}

	public function test_overrides_and_unmapped_property_fallback_keep_their_precedence(): void {
		$package = new class() extends AbstractPackage {
			protected $version     = 'raw-version';
			protected $raw         = 'raw-value';
			protected $absent      = null;
			protected $custom      = 'raw-custom';
			protected $_identifier = 'raw-underscore-identifier'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Fixture proves existing underscore field fallback after getter migration.

			public function get_identifier(): mixed {
				return 'override-id';
			}

			public function get_version(): string {
				return 'getter-version';
			}

			// Intentional legacy spelling: this fixture proves existing magic getter fallback precedence.
			// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Intentional legacy getter fixture proves existing magic fallback precedence.
			public function getCustom(): string {
				return 'legacy-custom';
			}

			public function get_custom(): string {
				return 'underscore-custom';
			}
		};

		self::assertSame( 'getter-version', $package->version );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Intentional case-variant probe of the magic getter contract.
		self::assertSame( 'getter-version', $package->VERSION );
		self::assertSame( 'raw-value', $package->raw );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Intentional case-variant probe of the magic getter contract.
		self::assertNull( $package->RAW );
		self::assertNull( $package->absent );
		self::assertNull( $package->unknown );
		self::assertNull( $package->{''} );
		self::assertSame( 'legacy-custom', $package->custom );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Intentional case-variant probe of the magic getter contract.
		self::assertSame( 'legacy-custom', $package->CUSTOM );
		self::assertSame( 'underscore-custom', $package->_custom );
		self::assertSame( 'raw-underscore-identifier', $package->_identifier );
		self::assertSame( 'override-id', (string) $package );
	}

	public function test_getter_failure_propagates_without_exposing_the_backing_field(): void {
		$package = new class() extends AbstractPackage {
			protected $version = 'private-backing-value';

			public function get_identifier(): mixed {
				return 'throwing-id';
			}

			public function get_version(): string {
				throw new RuntimeException( 'getter failure' );
			}
		};

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'getter failure' );
		$package->version;
	}
}

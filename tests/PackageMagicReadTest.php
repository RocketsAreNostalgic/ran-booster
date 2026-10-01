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

	public function testExistingGetterPropertyReadsRetainTheirValuesAndCaseInsensitivity(): void {
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
					self::assertNull( $package->$snake );
				}
			}
			self::assertSame( $identifier, (string) $package );
			self::assertNull( $package->unknown );
			self::assertNull( $package->{''} );
		}
	}

	public function testOverridesAndUnmappedPropertyFallbackKeepTheirPrecedence(): void {
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

			public function getCustom(): string {
				return 'legacy-custom';
			}

			public function get_custom(): string {
				return 'underscore-custom';
			}
		};

		self::assertSame( 'getter-version', $package->version );
		self::assertSame( 'getter-version', $package->VERSION );
		self::assertSame( 'raw-value', $package->raw );
		self::assertNull( $package->RAW );
		self::assertNull( $package->absent );
		self::assertNull( $package->unknown );
		self::assertNull( $package->{''} );
		self::assertSame( 'legacy-custom', $package->custom );
		self::assertSame( 'legacy-custom', $package->CUSTOM );
		self::assertSame( 'underscore-custom', $package->_custom );
		self::assertSame( 'raw-underscore-identifier', $package->_identifier );
		self::assertSame( 'override-id', (string) $package );
	}

	public function testGetterFailurePropagatesWithoutExposingTheBackingField(): void {
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

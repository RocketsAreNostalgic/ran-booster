<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\Deployment\DeploymentOutcome;
use RAN\WPBranchUpdater\V1\Archive\ArchiveValidator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

#[CoversClass( AdmittedBranchHostAdapter::class )]
final class AdmittedBranchValidatorOutcomesTest extends TestCase {

	private const UNMAPPED = array( 1010 );

	/** @return array<int, string> */
	private static function table(): array {
		$table = ( new ReflectionClassConstant( AdmittedBranchHostAdapter::class, 'VALIDATOR_OUTCOMES' ) )->getValue();
		self::assertIsArray( $table );
		$result = array();
		foreach ( $table as $code => $outcome ) {
			self::assertIsInt( $code );
			self::assertIsString( $outcome );
			$result[ $code ] = $outcome;
		}
		return $result;
	}

	public function test_every_validator_cause_is_mapped_or_explicitly_unmapped(): void {
		$table = self::table();
		foreach ( ( new ReflectionClass( ArchiveValidator::class ) )->getReflectionConstants() as $constant ) {
			if ( ! str_starts_with( $constant->getName(), 'CODE_' ) ) {
				continue;
			}
			$code = $constant->getValue();
			self::assertIsInt( $code );
			self::assertTrue( isset( $table[ $code ] ) xor in_array( $code, self::UNMAPPED, true ), $constant->getName() . ' must be mapped or listed as unmapped.' );
		}
		foreach ( $table as $outcome ) {
			self::assertSame( $outcome, DeploymentOutcome::from_code( $outcome )->get_code() );
		}
	}

	public function test_mapping_is_exact(): void {
		self::assertSame(
			array(
				1000 => 'deployment_zip_extension_missing',
				1001 => 'archive_zip_invalid',
				1002 => 'archive_entry_limit',
				1003 => 'archive_entry_invalid',
				1004 => 'archive_path_unsafe',
				1005 => 'archive_path_collision',
				1006 => 'archive_layout_invalid',
				1007 => 'archive_entry_unsupported',
				1008 => 'archive_encrypted',
				1009 => 'archive_compressed_too_large',
				1011 => 'package_subdirectory_missing',
				1012 => 'package_identity_mismatch',
				1013 => 'package_header_unreadable',
				1014 => 'package_version_invalid',
				1015 => 'package_compatibility_invalid',
				1016 => 'package_requires_newer_php',
				1017 => 'package_requires_newer_wordpress',
				1018 => 'archive_expanded_too_large',
				1019 => 'package_plugin_missing',
				1020 => 'package_theme_missing',
				1021 => 'package_multiple_plugins',
				1022 => 'package_header_missing',
				1023 => 'package_version_missing',
				1024 => 'archive_layout_invalid',
				1025 => 'archive_layout_invalid',
				1026 => 'package_identity_mismatch',
				1027 => 'archive_path_collision',
			),
			self::table()
		);
	}

	public function test_failures_resolve_to_mapped_cleanup_or_generic_outcomes(): void {
		$resolve = new ReflectionMethod( AdmittedBranchHostAdapter::class, 'archive_failure_outcome' );
		self::assertSame( 'archive_cleanup_failed', $resolve->invoke( null, new RuntimeException( 'cleanup', 0, new RuntimeException( 'primary', 1001 ) ) ) );
		self::assertSame( 'archive_zip_invalid', $resolve->invoke( null, new RuntimeException( 'x', 1001 ) ) );
		self::assertSame( 'archive_integrity_failed', $resolve->invoke( null, new RuntimeException( 'x', 1010 ) ) );
		self::assertSame( 'archive_integrity_failed', $resolve->invoke( null, new \LogicException( 'x', 1001 ) ) );
	}
}

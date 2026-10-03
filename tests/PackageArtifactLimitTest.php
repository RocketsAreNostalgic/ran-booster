<?php

declare(strict_types=1);

namespace Tests;

use ArgumentCountError;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\PackageArtifactLimit;
use ReflectionMethod;

final class PackageArtifactLimitTest extends TestCase {
	public function test_default_resolves_from_the_single_booster_authority(): void {
		self::assertSame(
			PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES,
			PackageArtifactLimit::resolve()
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_configured_site_limit_resolves_from_the_single_booster_authority(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 1048576 );

		self::assertSame( 1048576, PackageArtifactLimit::resolve() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_configured_site_limit_fails_closed_at_resolution(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', PackageArtifactLimit::MINIMUM_ARTIFACT_BYTES - 1 );

		$this->expectException( InvalidArgumentException::class );
		PackageArtifactLimit::resolve();
	}

	public function test_positional_override_is_rejected_instead_of_silently_ignored(): void {
		$this->expectException( ArgumentCountError::class );
		( new ReflectionMethod( PackageArtifactLimit::class, 'resolve' ) )->invoke( null, 1048576 );
	}

	public function test_obsolete_null_argument_is_rejected(): void {
		$this->expectException( ArgumentCountError::class );
		( new ReflectionMethod( PackageArtifactLimit::class, 'resolve' ) )->invoke( null, null );
	}

	public function test_obsolete_named_argument_is_rejected(): void {
		$this->expectException( Error::class );
		$this->expectExceptionMessage( 'Unknown named parameter $legacy_null' );
		( new ReflectionMethod( PackageArtifactLimit::class, 'resolve' ) )->invokeArgs( null, array( 'legacy_null' => null ) );
	}

	public function test_published_maximum_fits_pinned_updater_expanded_ceiling_on32_bit_php(): void {
		$maximum32_bit_safe_compressed_bytes = intdiv( 2147483647, 4 );

		self::assertSame( 536870911, $maximum32_bit_safe_compressed_bytes );
		self::assertSame( $maximum32_bit_safe_compressed_bytes, PackageArtifactLimit::MAXIMUM_ARTIFACT_BYTES );
		self::assertSame(
			$maximum32_bit_safe_compressed_bytes,
			PackageArtifactLimit::require_valid( $maximum32_bit_safe_compressed_bytes )
		);
	}

	public function test_exact512_mi_bendpoint_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PackageArtifactLimit::require_valid( 536870912 );
	}
}

<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\PackageArtifactLimit;
use ReflectionMethod;
use TypeError;

final class PackageArtifactLimitTest extends TestCase {
	public function testDefaultResolvesFromTheSingleBoosterAuthority(): void {
		self::assertSame(
			PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES,
			PackageArtifactLimit::resolve()
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testConfiguredSiteLimitResolvesFromTheSingleBoosterAuthority(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 1048576 );

		self::assertSame( 1048576, PackageArtifactLimit::resolve() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testInvalidConfiguredSiteLimitFailsClosedAtResolution(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', PackageArtifactLimit::MINIMUM_ARTIFACT_BYTES - 1 );

		$this->expectException( InvalidArgumentException::class );
		PackageArtifactLimit::resolve();
	}

	public function testLegacyNullCallRemainsCompatibleWithoutRestoringIntegerOverride(): void {
		self::assertSame( PackageArtifactLimit::resolve(), PackageArtifactLimit::resolve( null ) );

		$this->expectException( TypeError::class );
		( new ReflectionMethod( PackageArtifactLimit::class, 'resolve' ) )->invoke( null, 1048576 );
	}

	public function testPublishedMaximumFitsPinnedUpdaterExpandedCeilingOn32BitPhp(): void {
		$maximum32BitSafeCompressedBytes = intdiv( 2147483647, 4 );

		self::assertSame( 536870911, $maximum32BitSafeCompressedBytes );
		self::assertSame( $maximum32BitSafeCompressedBytes, PackageArtifactLimit::MAXIMUM_ARTIFACT_BYTES );
		self::assertSame(
			$maximum32BitSafeCompressedBytes,
			PackageArtifactLimit::requireValid( $maximum32BitSafeCompressedBytes )
		);
	}

	public function testExact512MiBEndpointIsRejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PackageArtifactLimit::requireValid( 536870912 );
	}
}

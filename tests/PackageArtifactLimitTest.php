<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\PackageArtifactLimit;

final class PackageArtifactLimitTest extends TestCase {
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

<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;

final class RepositoryBrowseRequestTest extends TestCase {

	public function testOneRequestCannotClaimMoreThanFiveRemoteCalls(): void {
		$request = RepositoryBrowseRequest::accessible( 'profile' );

		for ( $call = 0; $call < RepositoryBrowseRequest::MAX_REMOTE_CALLS; ++$call ) {
			$timeout = $request->claimRemoteCall();
			self::assertGreaterThan( 0.0, $timeout );
			self::assertLessThanOrEqual( 3.0, $timeout );
		}

		self::assertFalse( $request->hasCapacity() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 503 );
		$request->claimRemoteCall();
	}

	public function testPerResponseAndAggregateByteLimitsAreEnforced(): void {
		$perResponse = RepositoryBrowseRequest::accessible( 'profile' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 413 );
		$perResponse->acceptResponseBody( str_repeat( 'x', RepositoryBrowseRequest::PER_RESPONSE_BYTES + 1 ) );
	}

	public function testAggregateResponseLimitAllowsFourMaximumResponsesAndRejectsMore(): void {
		$request = RepositoryBrowseRequest::accessible( 'profile' );
		for ( $response = 0; $response < 4; ++$response ) {
			$request->acceptResponseBody( str_repeat( 'x', RepositoryBrowseRequest::PER_RESPONSE_BYTES ) );
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 413 );
		$request->acceptResponseBody( 'x' );
	}

	public function testExpiredDeadlineRejectsARequestBeforeNetworkWork(): void {
		$request = RepositoryBrowseRequest::accessible( 'profile' );
		$started = new \ReflectionProperty( $request, 'startedAt' );
		$started->setValue( $request, hrtime( true ) - 9_000_000_000 );

		self::assertFalse( $request->hasCapacity() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 503 );
		$request->claimRemoteCall();
	}

	public function testProvidersCannotReturnMoreThanTheSharedResultLimit(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			'owner/repository',
			'repository',
			'42',
			false,
			'main',
			null
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 502 );
		new RepositoryBrowseResult(
			array_fill( 0, RepositoryBrowseRequest::MAX_RESULTS + 1, $repository )
		);
	}
}

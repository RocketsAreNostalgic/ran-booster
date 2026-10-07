<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;

final class RepositoryBrowseRequestTest extends TestCase {

	public function test_one_request_cannot_claim_more_than_five_remote_calls(): void {
		$request = RepositoryBrowseRequest::accessible( 'profile' );

		for ( $call = 0; $call < RepositoryBrowseRequest::MAX_REMOTE_CALLS; ++$call ) {
			$timeout = $request->claim_remote_call();
			self::assertGreaterThan( 0.0, $timeout );
			self::assertLessThanOrEqual( 3.0, $timeout );
		}

		self::assertFalse( $request->has_capacity() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 503 );
		$request->claim_remote_call();
	}

	public function test_per_response_and_aggregate_byte_limits_are_enforced(): void {
		$per_response = RepositoryBrowseRequest::accessible( 'profile' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 413 );
		$per_response->accept_response_body( str_repeat( 'x', RepositoryBrowseRequest::PER_RESPONSE_BYTES + 1 ) );
	}

	public function test_aggregate_response_limit_allows_four_maximum_responses_and_rejects_more(): void {
		$request = RepositoryBrowseRequest::accessible( 'profile' );
		for ( $response = 0; $response < 4; ++$response ) {
			$request->accept_response_body( str_repeat( 'x', RepositoryBrowseRequest::PER_RESPONSE_BYTES ) );
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 413 );
		$request->accept_response_body( 'x' );
	}

	public function test_expired_deadline_rejects_arequest_before_network_work(): void {
		$request = RepositoryBrowseRequest::accessible( 'profile' );
		$started = new \ReflectionProperty( $request, 'started_at' );
		$started->setValue( $request, hrtime( true ) - 9_000_000_000 );

		self::assertFalse( $request->has_capacity() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 503 );
		$request->claim_remote_call();
	}

	public function test_providers_cannot_return_more_than_the_shared_result_limit(): void {
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

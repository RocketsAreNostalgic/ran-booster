<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';

use Closure;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RuntimeException;

final class AuthenticatedPreparedArchiveTest extends TestCase {

	private const REF = '0123456789abcdef0123456789abcdef01234567';

	protected function setUp(): void {
		parent::setUp();

		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
	}

	protected function tearDown(): void {
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();

		parent::tearDown();
	}

	public function testDistinctSameAndCrossProviderArchivesCoexistWithoutCrossAuthentication(): void {
		$githubOne = new AuthenticatedPreparedArchive(
			'https://api.github.com/repos/example/plugin/zipball/' . self::REF,
			self::REF,
			$this->authorizer( 'Bearer github-one-canary' )
		);
		$githubTwo = new AuthenticatedPreparedArchive(
			'https://api.github.com/repos/example/theme/zipball/' . self::REF,
			self::REF,
			$this->authorizer( 'Bearer github-two-canary' )
		);
		$bitbucket = new AuthenticatedPreparedArchive(
			'https://bitbucket.org/example/plugin/get/' . self::REF . '.zip',
			self::REF,
			$this->authorizer( 'Basic bitbucket-canary' )
		);

		self::assertCount( 3, $this->filters() );
		self::assertCount( 3, $this->actions() );

		$arguments = $this->applyFilters( $githubOne->getUrl() );
		self::assertSame( 'Bearer github-one-canary', $arguments['headers']['Authorization'] );
		self::assertCount( 2, $this->filters() );

		$arguments = $this->applyFilters( $githubTwo->getUrl() );
		self::assertSame( 'Bearer github-two-canary', $arguments['headers']['Authorization'] );
		self::assertCount( 1, $this->filters() );

		$arguments = $this->applyFilters( $bitbucket->getUrl() );
		self::assertSame( 'Basic bitbucket-canary', $arguments['headers']['Authorization'] );
		self::assertSame( array(), $this->filters() );
		self::assertCount( 3, $this->actions() );

		$githubOne->cleanup();
		$githubTwo->cleanup();
		$bitbucket->cleanup();
		$this->assertNoHooks();
	}

	public function testPublicArchiveReservesAndReleasesItsExactUrl(): void {
		$url    = 'https://archives.example.test/public.zip';
		$public = new AuthenticatedPreparedArchive( $url, self::REF );

		$this->expectExceptionMessage( 'already prepared' );
		try {
			new AuthenticatedPreparedArchive( $url, self::REF, $this->authorizer( 'Bearer private-canary' ) );
		} finally {
			$public->cleanup();
		}
	}

	public function testReleasedPublicUrlCanBePreparedAgain(): void {
		$url    = 'https://archives.example.test/reusable.zip';
		$public = new AuthenticatedPreparedArchive( $url, self::REF );
		$public->cleanup();
		$private = new AuthenticatedPreparedArchive( $url, self::REF, $this->authorizer( 'Bearer private-canary' ) );

		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );

		$private->cleanup();
		$this->assertNoHooks();
	}

	public function testCloneCannotCreateOrReleaseASecondCleanupAuthority(): void {
		$original = $this->privateArchive( $this->authorizer( 'Bearer original-canary' ), '-clone' );

		try {
			clone $original;
			self::fail( 'Prepared archives must not be cloneable.' );
		} catch ( \Error $error ) {
			self::assertStringNotContainsString( 'original-canary', $error->getMessage() );
		}

		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );
		$this->assertDuplicateUrlRejected( $original->getUrl() );
		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );

		$original->cleanup();
		$replacement = new AuthenticatedPreparedArchive( $original->getUrl(), self::REF );
		$replacement->cleanup();
		$this->assertNoHooks();
	}

	public function testSerializedPublicCopyCannotReleaseItsOwnersReservation(): void {
		$url   = 'https://archives.example.test/serialized-public.zip';
		$owner = new AuthenticatedPreparedArchive( $url, self::REF );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exercise an adversarial copy of the runtime value.
		$serialized = serialize( $owner );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The allowlist contains only the value under test.
		$copy = unserialize( $serialized, array( 'allowed_classes' => array( AuthenticatedPreparedArchive::class ) ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $copy );
		$copy->cleanup();
		$this->assertDuplicateUrlRejected( $url );
		$this->assertNoHooks();

		$owner->cleanup();
		$replacement = new AuthenticatedPreparedArchive( $url, self::REF );
		$replacement->cleanup();
		$this->assertNoHooks();
	}

	public function testPreExistingMixedCaseAuthorizationFailsClosedAndCleansExplicitly(): void {
		$archive  = $this->privateArchive( $this->authorizer( 'Bearer archive-canary' ) );
		$callback = $this->filters()[0]['callback'];

		try {
			$callback( array( 'headers' => array( 'aUtHoRiZaTiOn' => 'foreign-secret-canary' ) ), $archive->getUrl() );
			self::fail( 'An inherited authorization header must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'foreign-secret-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'archive-canary', $exception->getMessage() );
		}

		self::assertSame( array(), $this->filters() );
		self::assertCount( 1, $this->actions() );
		$archive->cleanup();
		$this->assertNoHooks();
	}

	public function testThrowingAndMalformedAuthorizersExposeOnlyFixedFailures(): void {
		$authorizers = array(
			static function (): never {
				throw new RuntimeException( 'authorizer-secret-canary' );
			},
			static fn (): string => 'malformed-secret-canary',
			static fn ( array $arguments ): array => array(
				'headers' => array(
					'Authorization' => 'Bearer first-secret-canary',
					'authorization' => 'Bearer second-secret-canary',
				),
			),
			static fn ( array $arguments ): array => array( 'headers' => array( 'Authorization' => array( 'invalid-secret-canary' ) ) ),
		);

		foreach ( $authorizers as $index => $authorizer ) {
			$archive  = $this->privateArchive( Closure::fromCallable( $authorizer ), '-' . $index );
			$callback = $this->filters()[0]['callback'];

			try {
				$callback( array( 'headers' => array() ), $archive->getUrl() );
				self::fail( 'Unsafe authorizer output must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'Provider archive authentication could not be applied safely.', $exception->getMessage() );
				self::assertStringNotContainsString( 'secret-canary', $exception->getMessage() );
			}

			self::assertSame( array(), $this->filters() );
			self::assertCount( 1, $this->actions() );
			$archive->cleanup();
			$this->assertNoHooks();
		}
	}

	public function testCleanupIsIdempotentAndVerifierSurvivesIt(): void {
		$verified = 0;
		$archive  = new AuthenticatedPreparedArchive(
			'https://archives.example.test/verifier.zip',
			self::REF,
			$this->authorizer( 'Bearer verifier-canary' ),
			static function () use ( &$verified ): void {
				++$verified;
			}
		);
		$callback = $this->filters()[0]['callback'];

		$archive->cleanup();
		$archive->cleanup();
		$archive->verifyCurrentHead();

		self::assertSame( 1, $verified );
		$this->assertNoHooks();

		try {
			$callback( array( 'headers' => array() ), $archive->getUrl() );
			self::fail( 'Cleaned authentication must remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'verifier-canary', $exception->getMessage() );
		}
	}

	private function privateArchive( Closure $authorizer, string $suffix = '' ): AuthenticatedPreparedArchive {
		return new AuthenticatedPreparedArchive(
			'https://archives.example.test/private' . $suffix . '.zip',
			self::REF,
			$authorizer
		);
	}

	private function authorizer( string $authorization ): Closure {
		return static function ( array $arguments ) use ( $authorization ): array {
			$arguments['headers']['Authorization'] = $authorization;

			return $arguments;
		};
	}

	private function assertDuplicateUrlRejected( string $url ): void {
		try {
			new AuthenticatedPreparedArchive( $url, self::REF );
			self::fail( 'A reserved archive URL must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The provider archive URL is already prepared for this request.', $exception->getMessage() );
		}
	}

	/** @return array<string, mixed> */
	private function applyFilters( string $url ): array {
		$arguments = array( 'headers' => array() );
		foreach ( $this->filters() as $filter ) {
			$arguments = $filter['callback']( $arguments, $url );
		}

		return $arguments;
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function filters(): array {
		return \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' );
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function actions(): array {
		return \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK );
	}

	private function assertNoHooks(): void {
		self::assertSame( array(), $this->filters() );
		self::assertSame( array(), $this->actions() );
	}
}

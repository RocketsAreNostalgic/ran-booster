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

	public function test_distinct_same_and_cross_provider_archives_coexist_without_cross_authentication(): void {
		$github_one = new AuthenticatedPreparedArchive(
			'https://api.github.com/repos/example/plugin/zipball/' . self::REF,
			self::REF,
			$this->authorizer( 'Bearer github-one-canary' )
		);
		$github_two = new AuthenticatedPreparedArchive(
			'https://api.github.com/repos/example/theme/zipball/' . self::REF,
			self::REF,
			$this->authorizer( 'Bearer github-two-canary' )
		);
		$bitbucket  = new AuthenticatedPreparedArchive(
			'https://bitbucket.org/example/plugin/get/' . self::REF . '.zip',
			self::REF,
			$this->authorizer( 'Basic bitbucket-canary' )
		);

		self::assertCount( 3, $this->filters() );
		self::assertCount( 3, $this->actions() );

		$arguments = $this->apply_filters( $github_one->get_url() );
		self::assertSame( 'Bearer github-one-canary', $arguments['headers']['Authorization'] );
		self::assertCount( 2, $this->filters() );

		$arguments = $this->apply_filters( $github_two->get_url() );
		self::assertSame( 'Bearer github-two-canary', $arguments['headers']['Authorization'] );
		self::assertCount( 1, $this->filters() );

		$arguments = $this->apply_filters( $bitbucket->get_url() );
		self::assertSame( 'Basic bitbucket-canary', $arguments['headers']['Authorization'] );
		self::assertSame( array(), $this->filters() );
		self::assertCount( 3, $this->actions() );

		$github_one->cleanup();
		$github_two->cleanup();
		$bitbucket->cleanup();
		$this->assert_no_hooks();
	}

	public function test_public_archive_reserves_and_releases_its_exact_url(): void {
		$url    = 'https://archives.example.test/public.zip';
		$public = new AuthenticatedPreparedArchive( $url, self::REF );

		$this->expectExceptionMessage( 'already prepared' );
		try {
			new AuthenticatedPreparedArchive( $url, self::REF, $this->authorizer( 'Bearer private-canary' ) );
		} finally {
			$public->cleanup();
		}
	}

	public function test_released_public_url_can_be_prepared_again(): void {
		$url    = 'https://archives.example.test/reusable.zip';
		$public = new AuthenticatedPreparedArchive( $url, self::REF );
		$public->cleanup();
		$private = new AuthenticatedPreparedArchive( $url, self::REF, $this->authorizer( 'Bearer private-canary' ) );

		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );

		$private->cleanup();
		$this->assert_no_hooks();
	}

	public function test_clone_cannot_create_or_release_asecond_cleanup_authority(): void {
		$original = $this->private_archive( $this->authorizer( 'Bearer original-canary' ), '-clone' );

		try {
			clone $original;
			self::fail( 'Prepared archives must not be cloneable.' );
		} catch ( \Error $error ) {
			self::assertStringNotContainsString( 'original-canary', $error->getMessage() );
		}

		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );
		$this->assert_duplicate_url_rejected( $original->get_url() );
		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );

		$original->cleanup();
		$replacement = new AuthenticatedPreparedArchive( $original->get_url(), self::REF );
		$replacement->cleanup();
		$this->assert_no_hooks();
	}

	public function test_serialized_public_copy_cannot_release_its_owners_reservation(): void {
		$url   = 'https://archives.example.test/serialized-public.zip';
		$owner = new AuthenticatedPreparedArchive( $url, self::REF );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exercise an adversarial copy of the runtime value.
		$serialized = serialize( $owner );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The allowlist contains only the value under test.
		$copy = unserialize( $serialized, array( 'allowed_classes' => array( AuthenticatedPreparedArchive::class ) ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $copy );
		$copy->cleanup();
		$this->assert_duplicate_url_rejected( $url );
		$this->assert_no_hooks();

		$owner->cleanup();
		$replacement = new AuthenticatedPreparedArchive( $url, self::REF );
		$replacement->cleanup();
		$this->assert_no_hooks();
	}

	public function test_pre_existing_mixed_case_authorization_fails_closed_and_cleans_explicitly(): void {
		$archive  = $this->private_archive( $this->authorizer( 'Bearer archive-canary' ) );
		$callback = $this->filters()[0]['callback'];

		try {
			$callback( array( 'headers' => array( 'aUtHoRiZaTiOn' => 'foreign-secret-canary' ) ), $archive->get_url() );
			self::fail( 'An inherited authorization header must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'foreign-secret-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'archive-canary', $exception->getMessage() );
		}

		self::assertSame( array(), $this->filters() );
		self::assertCount( 1, $this->actions() );
		$archive->cleanup();
		$this->assert_no_hooks();
	}

	public function test_throwing_and_malformed_authorizers_expose_only_fixed_failures(): void {
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
			$archive  = $this->private_archive( Closure::fromCallable( $authorizer ), '-' . $index );
			$callback = $this->filters()[0]['callback'];

			try {
				$callback( array( 'headers' => array() ), $archive->get_url() );
				self::fail( 'Unsafe authorizer output must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'Provider archive authentication could not be applied safely.', $exception->getMessage() );
				self::assertStringNotContainsString( 'secret-canary', $exception->getMessage() );
			}

			self::assertSame( array(), $this->filters() );
			self::assertCount( 1, $this->actions() );
			$archive->cleanup();
			$this->assert_no_hooks();
		}
	}

	public function test_cleanup_is_idempotent_and_verifier_survives_it(): void {
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
		$archive->verify_current_head();

		self::assertSame( 1, $verified );
		$this->assert_no_hooks();

		try {
			$callback( array( 'headers' => array() ), $archive->get_url() );
			self::fail( 'Cleaned authentication must remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'verifier-canary', $exception->getMessage() );
		}
	}

	private function private_archive( Closure $authorizer, string $suffix = '' ): AuthenticatedPreparedArchive {
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

	private function assert_duplicate_url_rejected( string $url ): void {
		try {
			new AuthenticatedPreparedArchive( $url, self::REF );
			self::fail( 'A reserved archive URL must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The provider archive URL is already prepared for this request.', $exception->getMessage() );
		}
	}

	/** @return array<string, mixed> */
	private function apply_filters( string $url ): array {
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

	private function assert_no_hooks(): void {
		self::assertSame( array(), $this->filters() );
		self::assertSame( array(), $this->actions() );
	}
}
